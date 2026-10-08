<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
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
 * An empty table is backfilled from the 90-day file on the first run, so a
 * new install can convert for orders placed before it first fetched.
 */
class FetchExchangeRates extends Command
{
    protected $signature = 'exchange-rates:fetch
        {--history : Backfill the last 90 days instead of fetching the latest day}';

    protected $description = 'Fetch the ECB euro reference rates used for customs value thresholds';

    public function handle(EcbReferenceRateFetcher $fetcher): int
    {
        try {
            $dates = $fetcher->fetch((bool) $this->option('history') || ExchangeRate::query()->doesntExist());
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
