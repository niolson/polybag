<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Events\AddressValidationFailed;
use App\Events\ImportCompleted;
use App\Events\ManifestCreated;
use App\Events\PackageCancelled;
use App\Events\PackageCreated;
use App\Events\PackageShipped;
use App\Events\ShipmentImported;
use App\Events\ShipmentUpdated;
use App\Models\AuditLog;
use App\Models\LabelBatchItem;
use App\Models\Package;

class AuditLogListener
{
    public bool $afterCommit = true;

    public function handlePackageCreated(PackageCreated $event): void
    {
        AuditLog::record(
            AuditAction::PackageCreated,
            $event->package,
            newValues: [
                'shipment_id' => $event->shipment->id,
                'shipment_reference' => $event->shipment->shipment_reference,
            ],
            metadata: ['source' => $this->resolveSource($event->package)],
        );
    }

    public function handlePackageShipped(PackageShipped $event): void
    {
        AuditLog::record(
            AuditAction::PackageShipped,
            $event->package,
            newValues: [
                'tracking_number' => $event->package->tracking_number,
                'carrier' => $event->package->carrier,
                'service' => $event->package->service,
                'cost' => $event->package->cost,
            ],
            metadata: ['source' => $this->resolveSource($event->package)],
            userId: $event->package->shipped_by_user_id,
        );
    }

    public function handlePackageCancelled(PackageCancelled $event): void
    {
        AuditLog::record(
            AuditAction::PackageCancelled,
            $event->package,
            oldValues: [
                'tracking_number' => $event->package->tracking_number,
                'carrier' => $event->package->carrier,
                'service' => $event->package->service,
                'cost' => $event->package->cost,
            ],
        );
    }

    /**
     * Determine how the package was shipped: Batch Ship, Pack, Ship, or Manual Ship.
     */
    private function resolveSource(Package $package): string
    {
        if (LabelBatchItem::where('package_id', $package->id)->exists()) {
            return 'Batch Ship';
        }

        try {
            // Livewire actions go through livewire/update, so check the Referer header
            $referer = request()->header('referer', '');
            $path = parse_url($referer, PHP_URL_PATH) ?? '';
        } catch (\Throwable) {
            return 'Queue';
        }

        return match (true) {
            str_contains($path, '/pack/') => 'Pack',
            str_contains($path, '/ship/') => 'Ship',
            str_contains($path, '/manual-ship') => 'Manual Ship',
            default => 'Unknown',
        };
    }

    public function handleShipmentImported(ShipmentImported $event): void
    {
        AuditLog::record(
            AuditAction::ShipmentImported,
            $event->shipment,
            newValues: [
                'shipment_reference' => $event->shipment->shipment_reference,
            ],
        );
    }

    public function handleShipmentUpdated(ShipmentUpdated $event): void
    {
        AuditLog::record(
            AuditAction::ShipmentUpdated,
            $event->shipment,
        );
    }

    public function handleImportCompleted(ImportCompleted $event): void
    {
        AuditLog::record(
            AuditAction::ImportCompleted,
            metadata: [
                'source_name' => $event->sourceName,
                'stats' => $event->stats,
            ],
        );
    }

    public function handleManifestCreated(ManifestCreated $event): void
    {
        AuditLog::record(
            AuditAction::ManifestCreated,
            $event->manifest,
            metadata: [
                'carrier' => $event->manifest->carrier,
                'package_count' => $event->packageCount,
            ],
        );
    }

    public function handleAddressValidationFailed(AddressValidationFailed $event): void
    {
        AuditLog::record(
            AuditAction::AddressValidationFailed,
            $event->shipment,
            metadata: [
                'reason' => $event->reason,
            ],
        );
    }
}
