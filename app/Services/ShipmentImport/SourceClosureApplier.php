<?php

namespace App\Services\ShipmentImport;

use App\DataTransferObjects\ShipmentImport\SourceOrderReport;
use App\DataTransferObjects\ShipmentImport\SourceParcel;
use App\Enums\AuditAction;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Models\AuditLog;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Services\PickBatchService;
use Illuminate\Support\Facades\DB;

/**
 * Closes an open Shipment its source has closed (`order-reconciliation`
 * decision 3). Shared by every source that reconciles on import: each reads
 * its own answer into a {@see SourceOrderReport}, and this applies it.
 *
 * A Package Draft on the Shipment stays, so it can be unpacked and restocked
 * (decision 4). Nothing can be bought for a cancelled order, and buying for a
 * shipped one is a manager's reship.
 */
class SourceClosureApplier
{
    public function __construct(
        private readonly PickBatchService $pickBatches,
    ) {}

    /**
     * @return bool whether the Shipment was closed
     */
    public function apply(Shipment $shipment, SourceOrderReport $report): bool
    {
        $closed = DB::transaction(function () use ($shipment, $report): bool {
            $current = Shipment::query()->lockForUpdate()->find($shipment->id);

            // Closed meanwhile, or reopened by a manager, whose change wins
            // (decision 7).
            if ($current === null
                || $current->status !== ShipmentStatus::Open
                || $current->status_origin === ShipmentStatusOrigin::Manager) {
                return false;
            }

            $attributes = ['status' => $report->status, 'status_origin' => ShipmentStatusOrigin::Source];

            if ($report->status === ShipmentStatus::Shipped) {
                $foreignParcels = $this->foreignParcels($current, $report->parcels);

                // Every parcel the source lists is one of PolyBag's Labels. A
                // voided one is left on a closed order when cancelling its
                // fulfillment failed, and the order must stay open to ship again.
                if ($report->parcels !== [] && $foreignParcels === []) {
                    return false;
                }

                $parcel = $foreignParcels[0] ?? null;
                $attributes['external_carrier'] = $parcel?->carrier;
                $attributes['external_tracking_number'] = $parcel?->trackingNumber;
            }

            $before = ['status' => $current->status->value, 'status_origin' => $current->status_origin?->value];

            // Through the model, so `status_changed_at` is stamped for the
            // notice on the status field.
            $current->update($attributes);

            AuditLog::record(
                AuditAction::ShipmentUpdated,
                $current,
                oldValues: $before,
                newValues: [
                    'status' => $current->status->value,
                    'status_origin' => $current->status_origin?->value,
                    'external_carrier' => $current->external_carrier,
                    'external_tracking_number' => $current->external_tracking_number,
                ],
                metadata: ['reason' => $report->description],
            );

            $shipment->setRawAttributes($current->getAttributes(), sync: true);

            return true;
        });

        if ($closed) {
            // Pickers should not pull goods for an order that is no longer ours to ship.
            $this->pickBatches->removeFromActiveBatches($shipment);
        }

        return $closed;
    }

    /**
     * The parcels that are not PolyBag's: no tracking number, or one that none
     * of the Shipment's Labels carries, voided ones included.
     *
     * @param  list<SourceParcel>  $parcels
     * @return list<SourceParcel>
     */
    private function foreignParcels(Shipment $shipment, array $parcels): array
    {
        if ($parcels === []) {
            return [];
        }

        $ours = PackageLabel::query()
            ->whereHas('package', fn ($query) => $query->where('shipment_id', $shipment->id))
            ->whereNotNull('tracking_number')
            ->pluck('tracking_number')
            ->map(fn (string $number): string => self::normalize($number))
            ->flip();

        return array_values(array_filter(
            $parcels,
            fn (SourceParcel $parcel): bool => blank($parcel->trackingNumber)
                || ! $ours->has(self::normalize($parcel->trackingNumber)),
        ));
    }

    /**
     * A tracking number compared ignoring case and spaces.
     */
    private static function normalize(string $trackingNumber): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $trackingNumber));
    }
}
