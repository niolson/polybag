<?php

namespace App\Console\Commands;

use App\Enums\ShipmentStatus;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\PostageSources\OfferStore;
use Illuminate\Console\Command;

class DeleteAbandonedDraftShipments extends Command
{
    protected $signature = 'shipments:delete-abandoned-drafts
        {--hours=24 : How long a draft may go untouched}';

    protected $description = 'Delete Manual Ship drafts that no label was ever bought for';

    public function handle(OfferStore $offers): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $deleted = 0;
        $kept = 0;

        Shipment::query()
            ->where('status', ShipmentStatus::Draft)
            ->where('updated_at', '<', $cutoff)
            // A draft whose purchase left a Label behind is never thrown away.
            ->whereDoesntHave('packages.labels')
            ->with('packages')
            ->each(function (Shipment $shipment) use ($offers, &$deleted, &$kept): void {
                // Nor one whose purchase nobody has accounted for: a label
                // may exist, and the offer recording the attempt would go
                // with the Package.
                if ($shipment->packages->contains(fn (Package $package): bool => $offers->hasUnresolvedPurchase($package))) {
                    $kept++;

                    return;
                }

                // Its Package, items and quotes go with it (cascading keys).
                $shipment->delete();
                $deleted++;
            });

        $this->info("Deleted {$deleted} abandoned draft shipment(s).");

        if ($kept > 0) {
            $this->warn("Kept {$kept} draft shipment(s) with a label purchase nobody has accounted for.");
        }

        return self::SUCCESS;
    }
}
