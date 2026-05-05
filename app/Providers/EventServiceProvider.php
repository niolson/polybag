<?php

namespace App\Providers;

use App\Events\PackageCancelled;
use App\Events\PackageShipped;
use App\Events\TrackingStatusUpdated;
use App\Listeners\InvalidateDashboardCache;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        PackageShipped::class => [
            InvalidateDashboardCache::class,
        ],
        PackageCancelled::class => [
            InvalidateDashboardCache::class,
        ],
        TrackingStatusUpdated::class => [
            InvalidateDashboardCache::class,
        ],
    ];
}
