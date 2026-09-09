<?php

namespace App\Services\Carriers;

use App\Contracts\BlindPurchaseSource;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Enums\ServiceEvidence;
use App\Exceptions\Carriers\ShopifyLabelPurchaseException;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyShippingLabelService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Buys postage through Shopify Shipping rather than through a carrier account
 * of our own — the way to reach USPS Connect eCommerce rates without an NSA.
 *
 * Shopify is not a carrier, and since ADR-0002 decision 7 this no longer has to
 * pretend otherwise: it implements the offer seam only. Voiding, tracking and
 * manifest eligibility follow the postage source and live on
 * `ShopifyPostageSource`; carrier policy belongs to whichever carrier Shopify
 * picks, which is not known until the purchase comes back.
 *
 * Since ADR-0003 decision 6 it does not pretend to quote either. Shopify's
 * Admin API has no rate operation and exposes no price on a purchased label, so
 * what it sells is a {@see BlindPurchaseOffer} and never a `RateResponse`:
 *
 * - there is no price to state, so there is no price field to invent one in,
 *   and the cost recorded on the package is left null;
 * - no purchased service is reported either, so the package records the service
 *   as `unknown` and keeps what was asked for as a requested preference;
 * - the offer is advertised only for a client that has opted into blind
 *   purchase, and never reaches auto-ship, batch ship, shipping rules or
 *   `RateSelector` — none of which handle anything but rates;
 * - only shipments imported from an active Shopify data source are eligible,
 *   since a purchase is keyed to a Shopify fulfillment order.
 *
 * Service codes are `carrier:service` pairs for Shopify's
 * `preferredRateSelection` (`usps:usps_ground_advantage`), or the bare code
 * `auto` to let Shopify pick the rate the way its admin would. Either way they
 * are a preference we asked for, never a service we were sold.
 */
class ShopifyAdapter implements BlindPurchaseSource
{
    public const CARRIER_NAME = 'Shopify';

    /** Service code that leaves rate selection to Shopify. */
    public const AUTO_SERVICE_CODE = 'auto';

    /** How the seller is named to a packer choosing an offer. */
    public const SOURCE_LABEL = 'Shopify Shipping';

    /**
     * Service codes Shopify only quotes inside a weight band, as
     * `[minimum inclusive, maximum exclusive]` in pounds.
     *
     * UPS splits Ground Saver across two codes by weight and quotes exactly one
     * of them for any given parcel — `92` under a pound, `93` at a pound or
     * more. A rated carrier never has to know this: its rate response comes back
     * carrying whichever tier applies, so the other simply is not there. A blind
     * offer has no rate to filter, so both would be advertised from the catalog,
     * and the packer would be shown two lines with nothing to choose between
     * them — one of which is certain to fail with `RATES_NOT_FOUND` after the
     * box is taped shut and the purchase confirmed. Withdrawing the ineligible
     * one is the same discipline `shipmentAlreadyBoughtALabel()` applies to a
     * fulfillment order that can no longer be bought against.
     *
     * Keyed on the full `carrier:service` code because the bare service code is
     * only meaningful beside its carrier — `92` is UPS's, and another carrier
     * could reuse the string for something else entirely.
     */
    private const WEIGHT_BANDED_SERVICES = [
        'ups_shipping:92' => [0.0, 1.0],
        'ups_shipping:93' => [1.0, null],
    ];

    public function getCarrierName(): string
    {
        return self::CARRIER_NAME;
    }

    /**
     * Configured when any active Shopify data source exists — that data source
     * carries both the credentials and the fulfillment orders labels are bought
     * against.
     */
    public function isConfigured(): bool
    {
        return DataSource::query()
            ->where('active', true)
            ->where('source_type', ShopifySource::class)
            ->exists();
    }

    /**
     * Nothing bought here can be promised a special service.
     *
     * Not modesty about what USPS or UPS would do — the point is that Shopify
     * chooses the carrier and the rate itself, after the purchase, so any
     * promise made at quote time is one we have no way to keep. ADR-0002
     * decision 8 puts this judgement on the offer for exactly that reason:
     * asked as carrier policy it has no honest answer, because there is no
     * carrier yet.
     *
     * A shipment that hard-requires the service drops this offer, visibly. One
     * that merely prefers it keeps the offer and goes without.
     */
    public function offerCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::Unguaranteed;
    }

    /**
     * No cap to report for the same reason: the carrier that would insure the
     * parcel is not known until Shopify has bought the label.
     */
    public function offerDeclaredValueCap(): ?float
    {
        return null;
    }

    /**
     * What Shopify will sell for this package, priceless.
     *
     * Four gates, and none of them is an error worth telling a packer about:
     * the client has to have opted into blind purchase (ADR-0003 decision 5),
     * the shipment has to have come from a live Shopify data source with a
     * fulfillment order to buy against, no label can have been bought against
     * that fulfillment order already, and the selection has to be one we
     * actually catalogue.
     *
     * The opt-in is checked here rather than in `ShippingRateService` because
     * it is a fact about this kind of purchase, not about rate shopping: there
     * is no price and no service to consent to after the fact, so consent has
     * to be on file before the offer is shown at all.
     *
     * @param  array<string>  $serviceCodes
     * @return Collection<int, BlindPurchaseOffer>
     */
    public function blindPurchaseOffers(RateRequest $request, array $serviceCodes): Collection
    {
        if ($serviceCodes === [] || ! $request->packageId) {
            return collect();
        }

        $package = Package::with(['shipment.dataSource', 'shipment.client'])->find($request->packageId);

        if (! $package || ! $package->shipment?->client?->blind_purchase_enabled) {
            return collect();
        }

        $labelService = app(ShopifyShippingLabelService::class);

        if (! $labelService->canPurchaseFor($package)) {
            return collect();
        }

        if ($this->shipmentAlreadyBoughtALabel($package)) {
            return collect();
        }

        $names = CarrierService::query()
            ->whereHas('carrier', fn ($query) => $query->where('name', self::CARRIER_NAME))
            ->whereIn('service_code', $serviceCodes)
            ->pluck('name', 'service_code');

        $dataSourceId = $labelService->dataSourceFor($package)?->id;

        return collect($serviceCodes)
            ->filter(fn (string $code): bool => $names->has($code))
            ->filter(fn (string $code): bool => $this->weightAllows($code, (float) $package->weight))
            ->map(fn (string $code): BlindPurchaseOffer => new BlindPurchaseOffer(
                source: self::CARRIER_NAME,
                sourceLabel: self::SOURCE_LABEL,
                serviceCode: $code,
                selectionLabel: (string) $names->get($code),
                postageDataSourceId: $dataSourceId,
            ))
            ->values();
    }

    /**
     * Whether a weight-banded service can be sold for this package's weight.
     *
     * Unbanded codes always pass — most services have no weight rule, and one
     * we have not recorded is not one to guess at.
     *
     * An unweighed package withdraws a banded offer rather than having a tier
     * picked for it — `weight` is nullable and a `decimal:2` cast, so it arrives
     * here as `0.0` when there is no answer. Nothing can be sold without a
     * weight anyway: Shopify rejects the purchase with `TOTAL_WEIGHT_ZERO`, so
     * offering a choice between two tiers of a service that cannot be bought at
     * all would only move the failure later.
     */
    private function weightAllows(string $serviceCode, float $weight): bool
    {
        $band = self::WEIGHT_BANDED_SERVICES[$serviceCode] ?? null;

        if ($band === null) {
            return true;
        }

        if ($weight <= 0.0) {
            return false;
        }

        [$minimum, $maximum] = $band;

        return $weight >= $minimum && ($maximum === null || $weight < $maximum);
    }

    /**
     * Whether a label has already been bought against this shipment's
     * fulfillment order, or is being bought right now.
     *
     * `shopify_fulfillment_order_id` lives on the shipment, so every package of
     * a shipment buys against the same fulfillment order. A second purchase
     * asks Shopify to fulfill what it has already fulfilled, and what comes
     * back — `JOB_NOT_ENQUEUED` or `FULFILLMENT_ORDER_INVALID` — reaches the
     * packer verbatim, after the box is taped shut. Withdrawing the offer is
     * the honest answer: this shipment's remaining packages need postage from a
     * carrier account.
     *
     * A shipped sibling is the obvious case and not the dangerous one.
     * `ShopifyShippingLabelService` persists `shopify_purchase_result_id` the
     * moment Shopify accepts the mutation and `shopify_shipping_label_id` as
     * soon as a label exists — both *before* the package is marked shipped,
     * precisely so a download that fails does not lose a label the shop has
     * already been charged for. A sibling stuck in that state is `Unshipped`
     * and holds a live purchase, so status alone would let this package buy a
     * second label against the same fulfillment order.
     *
     * Those markers are therefore disqualifying until something clears them,
     * and the only thing that clears them is
     * `ShopifyFulfillmentSynchronizer::applyVoid()`, on a confirmed
     * Shopify-side void. That is also why `Void` status is not disqualifying:
     * voiding reopens the fulfillment order and strips the markers in the same
     * write, so a shipment whose only previous label was voided can buy
     * another one.
     */
    private function shipmentAlreadyBoughtALabel(Package $package): bool
    {
        return $package->shipment?->packages()
            ->whereKeyNot($package->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->where('status', PackageStatus::Shipped)
                ->orWhereNotNull('metadata->shopify_shipping_label_id')
                ->orWhereNotNull('metadata->shopify_purchase_result_id'))
            ->exists() ?? false;
    }

    /**
     * Shopify's shipping label ID for a package, or null when Shopify did not
     * sell it.
     *
     * Static because the channel export reads it to decide whether Shopify has
     * already fulfilled the order, and that path holds no adapter and wants no
     * registry — the same reason
     * {@see AmazonBuyShippingAdapter::shipmentIdFor()} is static.
     *
     * Reads the label ID rather than `postage_source` for two reasons. What
     * matters is that *Shopify* bought this label, and the label ID is the only
     * thing that says so. And it is cleared on a void: `applyVoid()` strips it
     * along with the other three markers, so a package that was voided and then
     * re-shipped on one of our own carrier accounts correctly exports again.
     */
    public static function shippingLabelIdFor(Package $package): ?string
    {
        $labelId = $package->metadata['shopify_shipping_label_id'] ?? null;

        return filled($labelId) ? (string) $labelId : null;
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        $package = $request->packageId ? Package::with('shipment.dataSource')->find($request->packageId) : null;

        if (! $package) {
            return ShipResponse::failure('Shopify Shipping labels can only be bought for a saved package.');
        }

        // The only way in. A Shopify label has no rate behind it by
        // construction, so a request carrying one instead of a blind offer came
        // from somewhere that still thinks this quotes.
        $offer = $request->blindOffer;

        if (! $offer) {
            return ShipResponse::failure('Shopify Shipping labels are bought as a blind purchase, which this request did not carry.');
        }

        [$carrierCode, $serviceCode] = $this->splitServiceCode($offer->serviceCode);

        $labelService = app(ShopifyShippingLabelService::class);

        try {
            $label = $labelService->purchase($package, $request, $carrierCode, $serviceCode);
        } catch (ShopifyLabelPurchaseException $e) {
            logger()->error('Shopify label purchase failed', [
                'package_id' => $package->id,
                'service_code' => $offer->serviceCode,
                'error' => $e->getMessage(),
            ]);

            return ShipResponse::failure($e->getMessage());
        }

        if (! $label->trackingNumber) {
            return ShipResponse::failure('Shopify bought the label but returned no tracking number.');
        }

        // Deliberately no cost: Shopify bills the merchant for the label and
        // exposes no price through the API, and a fabricated 0.00 would read as
        // a free label everywhere the cost is reported.
        return new ShipResponse(
            success: true,
            trackingNumber: $label->trackingNumber,
            cost: null,
            // What Shopify actually did, not what was asked for. Shopify may
            // ignore preferredRateSelection outright, and it can pick a carrier
            // PolyBag has no account with at all — DHL eCommerce, Canada Post —
            // so the carrier it reports is the only trustworthy record.
            carrier: self::carrierNameFor($label->trackingCompany ?? $carrierCode),
            // Shopify reports no purchased service, before or after the buy —
            // `ShippingLabel` has no service, service code, rate or price. What
            // was asked for is kept as the requested preference, which is audit
            // metadata and not the service value. ADR-0003 decisions 5 and 7.
            service: null,
            requestedService: $serviceCode === null ? null : $offer->selectionLabel,
            serviceEvidence: ServiceEvidence::Unknown,
            labelData: $label->labelData,
            labelOrientation: 'portrait',
            labelFormat: $label->labelFormat,
            labelDpi: $request->labelDpi,
            shipDate: $request->shipDate,
            // The postage was bought on the merchant's Shopify account, not on
            // one of ours — so the provenance is the data source the shipment
            // came from, which purchasing has just proved resolves.
            postageSource: PostageSource::PostageDataSource,
            postageDataSourceId: $labelService->dataSourceFor($package)?->id,
            metadata: array_filter([
                'shopify_shipping_label_id' => $label->shippingLabelId,
                'shopify_tracking_company' => $label->trackingCompany,
                'shopify_customs_form_url' => $label->customsFormUrl,
                'shopify_label_document_url' => $label->labelDocumentUrl,
                // The raw code beside the requested preference the package
                // records, so a selection Shopify silently ignored stays visible.
                'shopify_requested_service_code' => $offer->serviceCode,
            ], fn (?string $value): bool => filled($value)),
        );
    }

    /**
     * The carrier a Shopify string names, as PolyBag spells it.
     *
     * `ShippingLabel.trackingInfo.company` returns Shopify's own carrier code —
     * `ups_shipping`, not UPS — and that value becomes the package's carrier of
     * record. Anything outside the known vocabulary passes through untouched:
     * Shopify sells through nineteen carriers and reports the rest as names
     * already, so translating only what is known to be a code leaves a real
     * name alone rather than mangling it.
     */
    private static function carrierNameFor(?string $company): ?string
    {
        if (! filled($company)) {
            return null;
        }

        return ShopifyShippingLabelService::CARRIER_NAMES[Str::lower($company)] ?? $company;
    }

    /**
     * Split a `carrier:service` code into its Shopify parts. The `auto` code —
     * and anything without a carrier prefix — leaves the choice to Shopify.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function splitServiceCode(string $serviceCode): array
    {
        if (! str_contains($serviceCode, ':')) {
            return [null, null];
        }

        [$carrierCode, $service] = explode(':', $serviceCode, 2);

        return filled($carrierCode) && filled($service) ? [$carrierCode, $service] : [null, null];
    }
}
