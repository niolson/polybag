<?php

namespace App\Listeners;

use App\Enums\PackageStatus;
use App\Events\PackageShipped;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

class ExportShippedPackage implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 600, 1200];

    public function handle(PackageShipped $event): void
    {
        // The package is read afresh when the job runs. A label voided in the
        // meantime — recorded and voided in one step by
        // `UnresolvedPurchaseResolver`, or a quick void at the bench — must
        // not reach the channel as a fulfillment.
        if ($event->package->status !== PackageStatus::Shipped) {
            return;
        }

        $result = app(PackageExportService::class)->exportPackage($event->package);

        if ($result->shouldRetry()) {
            throw new RuntimeException(implode('; ', $result->retryableErrors));
        }
    }
}
