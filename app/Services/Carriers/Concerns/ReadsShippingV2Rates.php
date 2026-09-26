<?php

namespace App\Services\Carriers\Concerns;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\AmazonBuyShippingService;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\AmazonShippingAdapter;
use Saloon\Http\Response;

/**
 * What any adapter reading an Amazon Shipping v2 `getRates` reply needs:
 * {@see AmazonBuyShippingAdapter} for Amazon's own orders, and
 * {@see AmazonShippingAdapter} for Amazon Shipping sold to other channels
 * (`carrier-catalog-reset/15`). Both quote, stamp and buy through the same
 * API, so a rate is judged buyable, and described for its purchase, the same
 * way whichever sold it.
 */
trait ReadsShippingV2Rates
{
    /**
     * From the offering when there is one: {@see returnsSeparateCustomsDocument()}
     * reads the `CUSTOM_FORM` declaration off the stored rate, which is what
     * lifts the territory over-block — Amazon quotes Puerto Rico as plain
     * domestic USPS, and that offering declares no form.
     *
     * Without a rate (batch validation, before anything is quoted) the
     * offering cannot be read, so the answer is the lane's: a foreign country
     * gets Amazon's stated separate `CUSTOM_FORM`, and a territory gets the
     * plain domestic label `09` observed four times. An approximation of the
     * offering, corrected by the purchase-time check, which always has one.
     */
    public function customsDocumentDelivery(AddressData $from, AddressData $to, ?RateResponse $rate = null): CustomsDocumentDelivery
    {
        if ($rate !== null) {
            return $this->returnsSeparateCustomsDocument($rate)
                ? CustomsDocumentDelivery::Separate
                : CustomsDocumentDelivery::None;
        }

        return $from->country !== $to->country
            ? CustomsDocumentDelivery::Separate
            : CustomsDocumentDelivery::None;
    }

    /**
     * Whether buying this rate returns a customs document beside the label,
     * which the report printer has to be there for.
     *
     * Re-derived from the `supportedDocumentSpecifications` the offer stored,
     * the same way `packagingRequirementFor()` re-classifies — so the
     * purchase reads the offering the quote read, never the browser's copy of
     * the stamp. See {@see AmazonBuyShippingService::declaresCustomsForm()}
     * for why the offering and not the address.
     */
    public function returnsSeparateCustomsDocument(RateResponse $rate): bool
    {
        return AmazonBuyShippingService::declaresCustomsForm(
            (array) ($rate->metadata['supportedDocumentSpecifications'] ?? [])
        );
    }

    /**
     * The connection whose credentials sent this request — the one that was
     * resolved when it was prepared, loaded even if it has since been switched
     * off, because the reply is still that account's answer. The purchase
     * refuses an inactive connection on its own.
     */
    private function connectionThatSent(Response $response): ?DataSource
    {
        $connector = $response->getConnector();
        $id = $connector instanceof AmazonSpApiConnector ? $connector->dataSourceId() : null;

        return $id === null ? null : DataSource::find($id);
    }

    /**
     * Drop a rate whose purchase must carry `additionalInputs`.
     *
     * Nothing here can build them yet: the schema they must satisfy is
     * fetched per rate and has never been seen for this account
     * (`amazon-buy-shipping/09`), so a rate that asks for them would pass the
     * quote and fail the purchase. The same shape as
     * {@see hasPrintableDocument()} — refused before an offer is issued and
     * before the money.
     *
     * @param  array<string, mixed>  $rate
     */
    private function needsNoAdditionalInputs(array $rate): bool
    {
        return ! ($rate['requiresAdditionalInputs'] ?? false);
    }

    /**
     * A rate offering none of the formats the print path can send to QZ Tray
     * — ZPL raw, PNG and PDF through the pixel path — is unbuyable and is
     * dropped here rather than at purchase time.
     *
     * @param  array<string, mixed>  $rate
     */
    private function hasPrintableDocument(array $rate): bool
    {
        return collect($rate['supportedDocumentSpecifications'] ?? [])
            ->contains(fn (array $spec): bool => in_array($spec['format'] ?? null, ['PDF', 'ZPL', 'PNG'], true));
    }

    /**
     * Drop an offer whose required value-added service group can only be
     * answered with a surcharge nobody asked for.
     *
     * A required group must be answered with an option it offers
     * (`amazon-buy-shipping/10`), and
     * {@see AmazonBuyShippingService::valueAddedServicesFor()} answers with the
     * cheapest one when nothing was requested. Every group seen so far has a
     * free option — UPS's `NO_CONFIRMATION`, USPS's `DELIVERY_CONFIRMATION` —
     * but a group that had none would make that answer a paid signature the
     * quoted price did not include. That rate is dropped here, unless the
     * shipment asked for one of the paid options, in which case it is paying
     * for it knowingly.
     *
     * @param  array<string, mixed>  $rate
     * @param  list<string>  $wanted  Amazon's ids for the value-added services the shipment asked for
     */
    private function answersRequiredGroupsForFree(array $rate, array $wanted): bool
    {
        foreach ($rate['availableValueAddedServiceGroups'] ?? [] as $group) {
            if (! ($group['isRequired'] ?? false)) {
                continue;
            }

            $offered = collect($group['valueAddedServices'] ?? [])->pluck('id');

            if ($offered->intersect($wanted)->isNotEmpty()) {
                continue;
            }

            $cheapest = AmazonBuyShippingService::cheapestOption($group);

            if ($cheapest === null || (float) ($cheapest['cost']['value'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the purchase will need from the quote, and what a person reading the
     * offer later will want.
     *
     * `supportedDocumentSpecifications` is the load-bearing entry: the document
     * format has to be validated against the *chosen rate*, and re-quoting to
     * find out which sizes it offered would invalidate the token being spent.
     * The packaging requirement rides along for the same reason: the purchase
     * re-checks it against the Package, and must read the one the quote stated.
     *
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    private function rateMetadata(array $rate, PackagingRequirement $packagingRequirement): array
    {
        return array_filter([
            'amazonCarrierId' => $rate['carrierId'] ?? null,
            'amazonServiceId' => $rate['serviceId'] ?? null,
            PackagingRequirement::RATE_METADATA_KEY => $packagingRequirement->toArray(),
            'supportedDocumentSpecifications' => $rate['supportedDocumentSpecifications'] ?? [],
            // Whether a second document comes back, for the report-printer
            // gate — derived from the offering, and re-derived at purchase
            // from the specifications stored beside it.
            AmazonBuyShippingAdapter::CUSTOMS_DOCUMENT_METADATA_KEY => AmazonBuyShippingService::declaresCustomsForm(
                $rate['supportedDocumentSpecifications'] ?? []
            ),
            'availableValueAddedServiceGroups' => $rate['availableValueAddedServiceGroups'] ?? [],
            // Buy Shipping protection: a reason to prefer this offer that has
            // nothing to do with price, and worth keeping on the package.
            'benefits' => $rate['benefits'] ?? null,
            'promise' => $rate['promise'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function packageFor(RateRequest $request): ?Package
    {
        return $request->packageId
            ? Package::with(['shipment.dataSource', 'packageItems.shipmentItem', 'packageItems.product', 'location'])
                ->find($request->packageId)
            : null;
    }
}
