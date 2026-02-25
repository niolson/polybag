<?php

namespace App\Providers;

use App\Events\PackageShipped;
use App\Listeners\ExportShippedPackage;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        PackageShipped::class => [
            ExportShippedPackage::class,
        ],
    ];
}
