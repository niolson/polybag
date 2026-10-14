<?php

namespace App\Listeners;

use App\Events\PackageCancelled;
use App\Events\PackageShipped;
use App\Jobs\RebuildDailyShippingStats;

/**
 * Queue a rebuild of the day a Label counts on: the new Label's ship date on
 * a purchase, the voided Label's on a void. Without the second, a Label voided
 * after its ship day stays in that day's rollup for good, and a re-buy counts
 * twice.
 */
class RebuildShippingStatsForShipDate
{
    public function handle(PackageShipped|PackageCancelled $event): void
    {
        $shipDate = $event instanceof PackageCancelled
            ? $event->voidedLabel->shipDate
            : $event->package->ship_date;

        if ($shipDate === null) {
            return;
        }

        RebuildDailyShippingStats::dispatch($shipDate->toDateString())->afterCommit();
    }
}
