<?php

namespace App\Services\ExchangeRates;

use App\DataTransferObjects\Customs\ConvertedAmount;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Converts an amount in a Shipment's own currency into the currency a rule is
 * stated in (`order-currency/01`): the EEI threshold and the carriage
 * declared value in US dollars, the IOSS, UK and VOEC thresholds in theirs.
 *
 * Every conversion for a Shipment is made on one ECB day, pinned on the
 * Shipment's `customs_rate_date` by the first conversion that needs a rate
 * and reused ever after, so the answer cannot move because a later day's
 * rates were fetched between two quotes, or after an outage ends. A pinned
 * day whose row for a currency is missing is no rate, never a reason to pick
 * another day. Nothing is pinned while no rate exists at all: the caller
 * holds what it was deciding, and the first conversion once rates arrive
 * pins.
 *
 * An amount already in the rule's currency needs no rate and pins nothing:
 * a EUR order into the EU, or a USD order against a dollar rule.
 *
 * The rate is the latest published before the order. A Shipment has no
 * order-date column yet, so its `created_at` (when it was imported) stands
 * in until an importer supplies one. A manager's edit to the Shipment does
 * not clear the pin: the order date, which is all the pin depends on, has
 * not changed.
 */
class ShipmentAmountConverter
{
    /**
     * How much older than the order's expected rate day a rate may be before
     * the conversion warns: the ECB never leaves more than a long holiday
     * weekend between publications, so more than this is a fetch that stopped.
     */
    public const STALE_RATE_DAYS = 5;

    public function __construct(private readonly ExchangeRateConverter $exchangeRates) {}

    /**
     * `$amount`, in the Shipment's currency, in `$to`; null when no stored
     * rate converts it. Warnings name `$purpose`, and are logged once per
     * Shipment, currency pair and day: the same conversion runs at every
     * quote, inspection and redemption.
     */
    public function convert(Shipment $shipment, float $amount, string $to, string $purpose): ?ConvertedAmount
    {
        $from = self::currencyOf($shipment);
        $to = strtoupper($to);
        $on = self::orderDate($shipment);

        if ($from === $to) {
            return new ConvertedAmount($amount, $to, $shipment->customs_rate_date ?? ExchangeRateConverter::latestPublishedDayBefore($on));
        }

        $context = [
            'shipment_id' => $shipment->id,
            'purpose' => $purpose,
            'from' => $from,
            'to' => $to,
            'order_date' => $on->toDateString(),
        ];

        if ($shipment->customs_rate_date !== null) {
            $converted = $this->exchangeRates->convertOn($amount, $from, $to, $shipment->customs_rate_date);

            if ($converted === null) {
                $this->warnOnce('pinned', $shipment, $from, $to, "The ECB exchange rate pinned for this Shipment is not stored for {$from} to {$to}; {$purpose} cannot be decided", $context + [
                    'rate_date' => $shipment->customs_rate_date->toDateString(),
                ]);
            }

            return $converted;
        }

        $converted = $this->exchangeRates->convert($amount, $from, $to, $on);

        if ($converted === null) {
            $this->warnOnce('missing', $shipment, $from, $to, "No ECB exchange rate from {$from} to {$to} stored on or before the order date; {$purpose} cannot be decided", $context);

            return null;
        }

        $expected = ExchangeRateConverter::latestPublishedDayBefore($on);

        if ($converted->rateDate->diffInDays($expected) > self::STALE_RATE_DAYS) {
            $this->warnOnce('stale', $shipment, $from, $to, "The ECB exchange rate used for {$purpose} is more than ".self::STALE_RATE_DAYS.' days older than the order', $context + [
                'rate_date' => $converted->rateDate->toDateString(),
            ]);
        }

        $pinned = $this->pinRateDate($shipment, $converted->rateDate);

        // Another conversion pinned first, on another day: its pin is the answer.
        if (! $pinned->equalTo($converted->rateDate)) {
            return $this->convert($shipment, $amount, $to, $purpose);
        }

        return $converted;
    }

    /**
     * The Shipment's currency, upper-cased, `USD` when unset.
     */
    public static function currencyOf(Shipment $shipment): string
    {
        return strtoupper(trim((string) ($shipment->currency ?: Shipment::DEFAULT_CURRENCY)));
    }

    /**
     * The day a rule is tested on. The EU tests IOSS at the ECB rate on the
     * day payment was accepted; PolyBag has no order date yet, so the
     * Shipment's `created_at` is used until an importer supplies one.
     */
    private static function orderDate(Shipment $shipment): CarbonInterface
    {
        return $shipment->created_at ?? now();
    }

    /**
     * Pin the day if none is pinned yet, and return whichever day is pinned.
     *
     * Written during quoting, so race-safe: only a null column is set, and the
     * value is read back, so two first conversions at once agree on the one
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
    private function warnOnce(string $kind, Shipment $shipment, string $from, string $to, string $message, array $context): void
    {
        $key = "shipment-amount:{$kind}-rate:{$shipment->id}:{$from}-{$to}:".now()->toDateString();

        if (Cache::add($key, true, now()->endOfDay())) {
            logger()->warning($message, $context);
        }
    }
}
