<?php

namespace App\Services\ExchangeRates;

use App\DataTransferObjects\Customs\ConvertedAmount;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Converts between currencies at the stored ECB euro reference rates
 * (`international-customs-terms/04`).
 *
 * Every ECB rate is quoted against the euro, so USD to NOK is USD to EUR and
 * EUR to NOK on the same day. The day used is the one asked for when the ECB
 * published that day, else the latest earlier day it published both
 * currencies. A later day is never used: a rate published after the order
 * was not the rate on the day payment was accepted.
 */
class ExchangeRateConverter
{
    /**
     * How many earlier published days to look through for one that quotes
     * both currencies. The ECB never skips more than a long holiday weekend;
     * this only bounds the query.
     */
    private const LOOKBACK_ROWS_PER_CURRENCY = 31;

    /**
     * The amount in `$to`, or null when no stored day on or before `$on`
     * quotes both currencies.
     */
    public function convert(float $amount, string $from, string $to, CarbonInterface $on): ?ConvertedAmount
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $day = CarbonImmutable::parse($on->toDateString());

        if ($from === $to) {
            return new ConvertedAmount($amount, $to, $day);
        }

        $quoted = array_values(array_diff([$from, $to], [ExchangeRate::BASE_CURRENCY]));

        $rates = ExchangeRate::query()
            ->whereIn('currency', $quoted)
            ->whereDate('rate_date', '<=', $day->toDateString())
            ->orderByDesc('rate_date')
            ->limit(self::LOOKBACK_ROWS_PER_CURRENCY * count($quoted))
            ->get(['rate_date', 'currency', 'rate'])
            ->groupBy(fn (ExchangeRate $rate): string => $rate->rate_date->toDateString());

        foreach ($rates as $date => $dayRates) {
            $perEuro = $dayRates->mapWithKeys(fn (ExchangeRate $rate): array => [$rate->currency => (float) $rate->rate])
                ->put(ExchangeRate::BASE_CURRENCY, 1.0);

            if (! $perEuro->has($from) || ! $perEuro->has($to)) {
                continue;
            }

            return new ConvertedAmount(
                amount: $amount / $perEuro->get($from) * $perEuro->get($to),
                currency: $to,
                rateDate: CarbonImmutable::parse($date),
            );
        }

        return null;
    }
}
