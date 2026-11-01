<?php

namespace App\Services\Customs;

use App\DataTransferObjects\Customs\ConvertedAmount;
use App\DataTransferObjects\Customs\CustomsLineOverLimit;
use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\Customs\SellerTaxRegistration;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\Enums\CustomsTermsOrigin;
use App\Enums\DutiesTerms;
use App\Enums\TaxRegistrationRegime;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\ExchangeRates\ShipmentAmountConverter;

/**
 * Turns a Shipment, its client and the destination into the customs terms it
 * ships on (ADR-0008 decisions 1–4; PRD *Resolution*).
 *
 *     duties term  = shipment.duties_terms
 *                  ?? client.duties_policy[country]
 *                  ?? client.duties_policy["EU"]      (EU destinations)
 *                  ?? DDU                              (non-EU destinations)
 *                  — an EU destination with none of these is unresolved
 *
 *     registration = the shipment's, when its regime covers the destination
 *                  ?? the client's for the regime that covers it
 *                  — withheld when the consignment is over an IOSS or UK VAT
 *                    threshold; reported per item for VOEC and ARN
 *
 * Customs values are in the Shipment's currency (`order-currency/01`), and
 * are converted into the threshold's at the Shipment's pinned ECB day
 * ({@see ShipmentAmountConverter}); a EUR order into the EU is compared as it
 * is. With no stored rate for the pair, the registration is withheld and a
 * warning logged: declaring a registration over its threshold is the
 * costlier mistake (IOSS: VAT charged on the whole consignment, and the VAT
 * collected at sale refunded).
 */
class CustomsTermsResolver
{
    public function __construct(private readonly ShipmentAmountConverter $amounts) {}

    /**
     * The terms for a Package's label, from its own customs lines.
     */
    public function forPackage(Package $package, ?AddressData $origin = null, ?AddressData $destination = null): ResolvedCustomsTerms
    {
        $shipment = $package->shipment;
        $destination ??= AddressData::fromShipment($shipment);

        $origin ??= $this->originFor($package);

        if ($this->crossesNoCustomsBorder($origin, $destination)) {
            return ResolvedCustomsTerms::notApplicable($destination->country, $shipment->client_id);
        }

        $package->loadMissing(['shipment.client.taxRegistrations', 'packageItems.product', 'packageItems.shipmentItem']);
        $shipment = $package->shipment;

        return $this->resolve(
            $shipment,
            $origin,
            $destination,
            $package->packageItems->map(CustomsItem::fromPackageItem(...))->values()->all(),
        );
    }

    /**
     * The destination country when a label to it is a customs crossing into a
     * regime whose duties terms nobody has verified a source decides: an EU,
     * GB, NO or AU destination, the countries a seller registration covers
     * ({@see TaxRegistrationRegime::covers()}). Null for every other
     * destination, and for a label that crosses no customs border.
     *
     * Automation does not buy Amazon Buy Shipping or Shopify Shipping postage
     * into these until a test purchase shows what the source declares
     * (ADR-0008 decision 5). Cheaper than {@see self::forPackage()}: it reads
     * only the two addresses, so a batch of hundreds asks it per package.
     */
    public function unverifiedSourceTermsDestination(Package $package): ?string
    {
        $destination = AddressData::fromShipment($package->shipment);
        $origin = $this->originFor($package);

        if ($this->crossesNoCustomsBorder($origin, $destination)) {
            return null;
        }

        foreach (TaxRegistrationRegime::cases() as $regime) {
            if ($regime->covers($destination)) {
                return strtoupper(trim($destination->country));
            }
        }

        return null;
    }

    private function originFor(Package $package): AddressData
    {
        $package->loadMissing('location');

        return $package->location !== null
            ? AddressData::fromLocation($package->location)
            : AddressData::fromConfig();
    }

    /**
     * @param  list<CustomsItem>  $lines  The customs lines the label declares
     */
    public function resolve(Shipment $shipment, AddressData $origin, AddressData $destination, array $lines): ResolvedCustomsTerms
    {
        $country = strtoupper(trim($destination->country));

        if ($this->crossesNoCustomsBorder($origin, $destination)) {
            return ResolvedCustomsTerms::notApplicable($country, $shipment->client_id);
        }

        $client = $shipment->client;
        [$dutiesTerms, $dutiesTermsOrigin] = $this->dutiesTerms($shipment, $client, $destination);
        $consignmentValue = round(array_sum(array_map(
            fn (CustomsItem $line): float => $line->unitValue * $line->quantity,
            $lines,
        )), 2);

        $registration = $this->registration($shipment, $client, $destination);

        $terms = [
            'applies' => true,
            'destinationCountry' => $country,
            'clientId' => $shipment->client_id,
            'dutiesTerms' => $dutiesTerms,
            'dutiesTermsOrigin' => $dutiesTermsOrigin,
            'consignmentValue' => $consignmentValue,
            'currency' => ShipmentAmountConverter::currencyOf($shipment),
            'recipientIsBusiness' => filled($destination->company),
        ];

        if ($registration === null) {
            return new ResolvedCustomsTerms(...$terms);
        }

        $regime = $registration->regime;
        $threshold = $regime->lowValueThresholdFor($destination);
        $currency = $regime->thresholdCurrencyFor($destination);
        $purpose = "the {$regime->getLabel()} threshold";

        $terms += [
            'applicableRegistration' => $registration,
            'threshold' => $threshold,
            'thresholdCurrency' => $currency,
        ];

        if ($regime->measuresEachItem()) {
            $linesOverLimit = [];

            foreach ($lines as $index => $line) {
                $converted = $this->amounts->convert($shipment, $line->unitValue, $currency, $purpose);

                if ($converted === null) {
                    return new ResolvedCustomsTerms(...$terms, overThreshold: true, exchangeRateMissing: true);
                }

                if ($regime->exceedsThreshold($converted->amount, $threshold)) {
                    $linesOverLimit[] = new CustomsLineOverLimit(
                        line: $index,
                        description: $line->description,
                        quantity: $line->quantity,
                        unitValue: $line->unitValue,
                        convertedUnitValue: round($converted->amount, 2),
                        currency: $currency,
                    );
                }
            }

            return new ResolvedCustomsTerms(...$terms, registration: $registration, linesOverLimit: $linesOverLimit);
        }

        $converted = $this->amounts->convert($shipment, $consignmentValue, $currency, $purpose);

        if ($converted === null) {
            return new ResolvedCustomsTerms(...$terms, overThreshold: true, exchangeRateMissing: true);
        }

        $overThreshold = $regime->exceedsThreshold($converted->amount, $threshold);

        return new ResolvedCustomsTerms(
            ...$terms,
            registration: $overThreshold ? null : $registration,
            overThreshold: $overThreshold,
            convertedValue: new ConvertedAmount(round($converted->amount, 2), $converted->currency, $converted->rateDate),
        );
    }

    /**
     * The duties term and where it came from; both null when unresolved.
     *
     * @return array{0: DutiesTerms|null, 1: CustomsTermsOrigin|null}
     */
    private function dutiesTerms(Shipment $shipment, ?Client $client, AddressData $destination): array
    {
        if ($shipment->duties_terms instanceof DutiesTerms) {
            return [$shipment->duties_terms, CustomsTermsOrigin::Order];
        }

        $policy = is_array($client?->duties_policy) ? $client->duties_policy : [];
        $country = strtoupper(trim($destination->country));

        if (($terms = DutiesTerms::fromInput($policy[$country] ?? null)) !== null) {
            return [$terms, CustomsTermsOrigin::Client];
        }

        if ($destination->isInEuropeanUnion()) {
            $terms = DutiesTerms::fromInput($policy[Client::DUTIES_POLICY_EU] ?? null);

            return $terms === null ? [null, null] : [$terms, CustomsTermsOrigin::Client];
        }

        return [DutiesTerms::Ddu, CustomsTermsOrigin::Default];
    }

    /**
     * The registration whose regime covers the destination: the order's,
     * which replaces the client's and never merges with it, else the
     * client's. An order registration under a regime that does not cover the
     * destination is ignored, and the client's is used as if the order had
     * none.
     *
     * Northern Ireland is covered by both IOSS and UK VAT; IOSS is preferred
     * when the client holds both, and UK VAT then serves the rest of GB.
     */
    private function registration(Shipment $shipment, ?Client $client, AddressData $destination): ?SellerTaxRegistration
    {
        $orderRegime = $shipment->seller_tax_regime;

        if ($orderRegime instanceof TaxRegistrationRegime
            && filled($shipment->seller_tax_number)
            && $orderRegime->covers($destination)) {
            return new SellerTaxRegistration($orderRegime, (string) $shipment->seller_tax_number, CustomsTermsOrigin::Order);
        }

        if ($client === null) {
            return null;
        }

        $registrations = $client->taxRegistrations
            ->keyBy(fn (ClientTaxRegistration $registration): string => $registration->regime->value);

        foreach (TaxRegistrationRegime::cases() as $regime) {
            $registration = $registrations->get($regime->value);

            if ($registration !== null && $regime->covers($destination)) {
                return new SellerTaxRegistration($regime, $registration->number, CustomsTermsOrigin::Client);
            }
        }

        return null;
    }

    /**
     * Whether the parcel stays inside one customs territory, and so has no
     * import to declare terms for.
     *
     * {@see AddressData::sharesCustomsZoneWith()} treats every non-US country
     * as its own zone, which is right for the declarations it gates but would
     * make a parcel from Germany to France an import. The EU is one customs
     * union, so an origin and destination both inside it cross nothing.
     * GB to Northern Ireland is left as `GB` to `GB`: the Windsor Framework
     * makes some such movements declarable, which nothing here models yet.
     */
    private function crossesNoCustomsBorder(AddressData $origin, AddressData $destination): bool
    {
        return $origin->sharesCustomsZoneWith($destination)
            || ($origin->isInEuropeanUnion() && $destination->isInEuropeanUnion());
    }
}
