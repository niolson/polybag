<?php

namespace App\Listeners;

use App\Events\PackageShipped;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Contracts\Queue\ShouldQueue;

class ExportShippedPackage implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(PackageShipped $event): void
    {
        app(PackageExportService::class)->exportPackage($event->package);
    }
}
