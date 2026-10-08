<?php

namespace App\Console\Commands;

use App\Services\ExchangeRates\EcbReferenceRateFetcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Stores the ECB's euro reference rates, which decide whether a consignment is
 * under a seller tax registration's low-value threshold
 * (`international-customs-terms/04`).
 *
 * A failed fetch is a warning in the log, not an error a person must act on
 * at once: the converter falls back to the latest earlier day, and with no
 * day at all it sends no registration rather than one over its threshold.
 *
 * Every run reads the ECB's 90-day file rather than the daily one. It is
 * small, the store is an idempotent upsert, and it means a new install can
 * convert for orders placed before it first fetched, and an outage of up to
 * ninety days heals on the next run instead of leaving a gap.
 */
class FetchExchangeRates extends Command
{
    protected $signature = 'exchange-rates:fetch';

    protected $description = 'Fetch the ECB euro reference rates used for customs value thresholds';

    public function handle(EcbReferenceRateFetcher $fetcher): int
    {
        try {
            $dates = $fetcher->fetch(lastNinetyDays: true);
        } catch (Throwable $e) {
            logger()->warning('Could not fetch the ECB euro reference rates', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->error('Could not fetch the ECB euro reference rates: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($dates) === 1
            ? "Stored ECB reference rates for {$dates[0]}."
            : 'Stored ECB reference rates for '.count($dates).' days, '.$dates[0].' to '.end($dates).'.');

        return self::SUCCESS;
    }
}
