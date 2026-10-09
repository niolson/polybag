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
use App\Services\ExchangeRates\ExchangeRateConverter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

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
 * Customs values are USD; thresholds are converted at the latest ECB
 * reference rate published before the order ({@see ExchangeRateConverter}). A Shipment has no order-date column yet, so its
 * `created_at` — when it was imported — stands in until an importer supplies
 * one. With no stored rate on or before that day, the registration is
 * withheld and a warning logged: declaring a registration over its threshold
 * is the costlier mistake (IOSS: VAT charged on the whole consignment, and
 * the VAT collected at sale refunded).
 */
class CustomsTermsResolver
{
    /**
     * How much older than the order's expected rate day a rate may be before
     * the conversion warns: the ECB never leaves more than a long holiday
     * weekend between publications, so more than this is a fetch that stopped.
     */
    private const STALE_RATE_DAYS = 5;

    public function __construct(private readonly ExchangeRateConverter $exchangeRates) {}

    /**
     * The terms for a Package's label, from its own customs lines.
     */
    public function forPackage(Package $package, ?AddressData $origin = null, ?AddressData $destination = null): ResolvedCustomsTerms
    {
        $shipment = $package->shipment;
        $destination ??= AddressData::fromShipment($shipment);

        if ($origin === null) {
            $package->loadMissing('location');
            $origin = $package->location !== null
                ? AddressData::fromLocation($package->location)
                : AddressData::fromConfig();
        }

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
            'recipientIsBusiness' => filled($destination->company),
        ];

        if ($registration === null) {
            return new ResolvedCustomsTerms(...$terms);
        }

        $regime = $registration->regime;
        $threshold = $regime->lowValueThresholdFor($destination);
        $currency = $regime->thresholdCurrencyFor($destination);
        $orderDate = $this->orderDate($shipment);

        $terms += [
            'applicableRegistration' => $registration,
            'threshold' => $threshold,
            'thresholdCurrency' => $currency,
        ];

        if ($regime->measuresEachItem()) {
            $linesOverLimit = [];

            foreach ($lines as $index => $line) {
                $converted = $this->convert($line->unitValue, $currency, $orderDate, $shipment, $regime);

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

        $converted = $this->convert($consignmentValue, $currency, $orderDate, $shipment, $regime);

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
     * The day the threshold is tested on. The EU tests IOSS at the ECB rate
     * on the day payment was accepted; PolyBag has no order date yet, so the
     * Shipment's `created_at` is used until an importer supplies one.
     */
    private function orderDate(Shipment $shipment): CarbonInterface
    {
        return $shipment->created_at ?? now();
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

    /**
     * Convert at the Shipment's pinned ECB day, or at the latest rate
     * published before the order, pinning the day that picked.
     *
     * The day is pinned on the Shipment (`customs_rate_date`) by the first
     * resolution that converts a value and reused ever after, so the answer
     * cannot move because a later day's rates were fetched between two
     * quotes, or after an outage ends. A pinned day whose row for this
     * currency is missing is no rate, never a reason to pick another day.
     * Nothing is pinned while no rate exists at all: the registration is
     * withheld, and the first resolution once rates arrive pins.
     *
     * A manager's edit to `duties_terms` or the seller registration does not
     * clear the pin: the order date, which is all the pin depends on, has not
     * changed.
     *
     * Warnings are logged once per Shipment, regime and day: the same
     * resolution runs at every quote, inspection and redemption, since the
     * Offer fingerprint is recomputed each time.
     */
    private function convert(float $usd, string $currency, CarbonInterface $on, Shipment $shipment, TaxRegistrationRegime $regime): ?ConvertedAmount
    {
        $context = [
            'shipment_id' => $shipment->id,
            'regime' => $regime->value,
            'currency' => $currency,
            'order_date' => $on->toDateString(),
        ];

        if ($shipment->customs_rate_date !== null) {
            $converted = $this->exchangeRates->convertOn($usd, 'USD', $currency, $shipment->customs_rate_date);

            if ($converted === null) {
                $this->warnOnce('pinned', $shipment, $regime, 'The ECB exchange rate pinned for this Shipment is not stored for this currency; the seller tax registration is not declared', $context + [
                    'rate_date' => $shipment->customs_rate_date->toDateString(),
                ]);
            }

            return $converted;
        }

        $converted = $this->exchangeRates->convert($usd, 'USD', $currency, $on);

        if ($converted === null) {
            $this->warnOnce('missing', $shipment, $regime, 'No ECB exchange rate stored on or before the order date; the seller tax registration is not declared', $context);

            return null;
        }

        $expected = ExchangeRateConverter::latestPublishedDayBefore($on);

        if ($converted->rateDate->diffInDays($expected) > self::STALE_RATE_DAYS) {
            $this->warnOnce('stale', $shipment, $regime, 'The ECB exchange rate used for a seller tax registration threshold is more than '.self::STALE_RATE_DAYS.' days older than the order', $context + [
                'rate_date' => $converted->rateDate->toDateString(),
            ]);
        }

        $pinned = $this->pinRateDate($shipment, $converted->rateDate);

        // Another resolution pinned first, on another day: its pin is the answer.
        if (! $pinned->equalTo($converted->rateDate)) {
            return $this->convert($usd, $currency, $on, $shipment, $regime);
        }

        return $converted;
    }

    /**
     * Pin the day if none is pinned yet, and return whichever day is pinned.
     *
     * Written during quoting, so race-safe: only a null column is set, and the
     * value is read back, so two first resolutions at once agree on the one
     * that landed. Written without touching `updated_at`, which keys the Ship
     * page's rate cache: pinning changes nothing a quote depends on.
     */
    private function pinRateDate(Shipment $shipment, CarbonImmutable $rateDate): CarbonImmutable
    {
        Shipment::query()
            ->whereKey($shipment->id)
            ->whereNull('customs_rate_date')
            ->toBase()
            ->update(['customs_rate_date' => $rateDate->toDateString()]);

        $stored = Shipment::query()->whereKey($shipment->id)->first(['id', 'customs_rate_date'])?->customs_rate_date;
        $pinned = $stored === null ? $rateDate : CarbonImmutable::parse($stored->toDateString());

        $shipment->setAttribute('customs_rate_date', $pinned->toDateString());
        $shipment->syncOriginalAttribute('customs_rate_date');

        return $pinned;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function warnOnce(string $kind, Shipment $shipment, TaxRegistrationRegime $regime, string $message, array $context): void
    {
        $key = "customs-terms:{$kind}-rate:{$shipment->id}:{$regime->value}:".now()->toDateString();

        if (Cache::add($key, true, now()->endOfDay())) {
            logger()->warning($message, $context);
        }
    }
}
