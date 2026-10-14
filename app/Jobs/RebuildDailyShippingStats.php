<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Rebuild one day's `daily_shipping_stats` rows, so the dashboard and the
 * Volume Report follow a purchase or a void on that day without waiting for
 * the schedule. Unique per date until it starts, so a burst of purchases
 * queues one rebuild while it waits.
 */
class RebuildDailyShippingStats implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    /**
     * On the `low` queue, behind any batch labels already queued there: on
     * `default` it would run between every two labels of a batch, since the
     * worker drains `default` first, and the uniqueness would coalesce
     * nothing.
     */
    public function __construct(
        public string $date,
    ) {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return $this->date;
    }

    public function handle(): void
    {
        Artisan::call('stats:aggregate', ['--from' => $this->date, '--to' => $this->date]);
    }
}
