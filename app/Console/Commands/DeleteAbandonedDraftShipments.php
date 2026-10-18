<?php

namespace App\Console\Commands;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Console\Command;

class DeleteAbandonedDraftShipments extends Command
{
    protected $signature = 'shipments:delete-abandoned-drafts
        {--hours=24 : How long a draft may go untouched}';

    protected $description = 'Delete Manual Ship drafts that no label was ever bought for';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $deleted = 0;

        Shipment::query()
            ->where('status', ShipmentStatus::Draft)
            ->where('updated_at', '<', $cutoff)
            // A draft whose purchase left a Label behind is never thrown away.
            ->whereDoesntHave('packages.labels')
            ->each(function (Shipment $shipment) use (&$deleted): void {
                // Its Package, items and quotes go with it (cascading keys).
                $shipment->delete();
                $deleted++;
            });

        $this->info("Deleted {$deleted} abandoned draft shipment(s).");

        return self::SUCCESS;
    }
}
