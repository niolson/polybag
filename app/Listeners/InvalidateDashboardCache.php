<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

class InvalidateDashboardCache implements ShouldQueue
{
    public bool $afterCommit = true;

    /**
     * All widget cache keys that should be invalidated when shipping data changes.
     */
    private const CACHE_KEYS = [
        'widget:stats_overview:v2',
        'widget:shipped_chart:week',
        'widget:shipped_chart:month',
        'widget:cost_trend:v2',
        'widget:carrier_breakdown:week',
        'widget:carrier_breakdown:month',
        'widget:exceptions:v2',
    ];

    public function handle(object $event): void
    {
        static::invalidateAll();
    }

    /**
     * Clear all dashboard widget caches.
     *
     * Used by event listeners and artisan commands.
     */
    public static function invalidateAll(): void
    {
        foreach (self::CACHE_KEYS as $key) {
            Cache::forget($key);
        }
    }
}
