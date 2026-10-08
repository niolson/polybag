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
 * EUR to NOK on the same day.
 *
 * The rate used is the latest one **published before** the moment asked
 * about. The ECB publishes each working day's rates at about 16:00 Central
 * European time, so a moment before 16:00 Europe/Berlin on a day uses the
 * previous published day, and one from 16:00 uses that day's. The answer is
 * therefore fixed once the moment has passed: it does not depend on whether
 * PolyBag had fetched that day's file when it asked, which would otherwise let
 * the same order be under a threshold in the morning and over it in the
 * afternoon. A day with no rate (weekends, TARGET holidays) falls back to the
 * latest earlier day that quotes both currencies.
 */
class ExchangeRateConverter
{
    /**
     * When the ECB's daily reference rates are published, in its own time.
     */
    public const PUBLICATION_TIMEZONE = 'Europe/Berlin';

    public const PUBLICATION_HOUR = 16;

    /**
     * How many earlier published days to look through for one that quotes
     * both currencies. The ECB never skips more than a long holiday weekend;
     * this only bounds the query.
     */
    private const LOOKBACK_ROWS_PER_CURRENCY = 31;

    /**
     * The latest ECB reference date published before `$at`.
     */
    public static function latestPublishedDayBefore(CarbonInterface $at): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at)->setTimezone(self::PUBLICATION_TIMEZONE);
        $day = CarbonImmutable::parse($local->toDateString());

        return $local->hour >= self::PUBLICATION_HOUR ? $day : $day->subDay();
    }

    /**
     * The amount in `$to` at the latest rate published before `$at`, or null
     * when no stored day on or before that one quotes both currencies.
     */
    public function convert(float $amount, string $from, string $to, CarbonInterface $at): ?ConvertedAmount
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $day = self::latestPublishedDayBefore($at);

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
