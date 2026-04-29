<?php

namespace App\Services;

use App\Enums\PickBatchStatus;
use App\Enums\PickingStatus;
use App\Enums\ShipmentStatus;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PickBatchService
{
    /**
     * Create a pick batch from a pre-selected collection of shipments.
     * Silently skips any that are not in 'pending' picking status.
     *
     * @param  Collection<int, Shipment>  $shipments
     */
    public function createFromShipments(Collection $shipments, User $user): ?PickBatch
    {
        $shipmentIds = $shipments->pluck('id')->filter()->values();

        if ($shipmentIds->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($shipmentIds, $user) {
            $eligible = Shipment::query()
                ->whereKey($shipmentIds)
                ->where('picking_status', PickingStatus::Pending)
                ->where('status', ShipmentStatus::Open)
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (Shipment $shipment) => $shipmentIds->search($shipment->id))
                ->values();

            if ($eligible->isEmpty()) {
                return null;
            }

            $batch = PickBatch::create([
                'user_id' => $user->id,
                'status' => PickBatchStatus::InProgress,
                'total_shipments' => $eligible->count(),
            ]);

            foreach ($eligible as $index => $shipment) {
                PickBatchShipment::create([
                    'pick_batch_id' => $batch->id,
                    'shipment_id' => $shipment->id,
                    'tote_code' => sprintf('T%02d', $index + 1),
                ]);
            }

            Shipment::whereKey($eligible->pluck('id'))
                ->update(['picking_status' => PickingStatus::Batched]);

            return $batch;
        });
    }

    /**
     * Auto-generate a pick batch by selecting up to $batchSize pending shipments.
     */
    public function autoGenerate(
        int $batchSize,
        bool $prioritizeExpedited,
        ?int $channelId,
        ?int $shippingMethodId,
        User $user,
    ): ?PickBatch {
        $query = Shipment::where('picking_status', PickingStatus::Pending)
            ->where('status', ShipmentStatus::Open)
            ->when($channelId, fn ($q) => $q->where('channel_id', $channelId))
            ->when($shippingMethodId, fn ($q) => $q->where('shipping_method_id', $shippingMethodId));

        if ($prioritizeExpedited) {
            $query->leftJoin('shipping_methods', 'shipments.shipping_method_id', '=', 'shipping_methods.id')
                ->orderByDesc('shipping_methods.is_expedited')
                ->orderBy('shipments.created_at')
                ->select('shipments.*');
        } else {
            $query->orderBy('created_at');
        }

        $shipments = $query->limit($batchSize)->get();

        return $this->createFromShipments($shipments, $user);
    }

    /**
     * Cancel a pick batch and return its shipments to pending.
     */
    public function cancel(PickBatch $batch): void
    {
        if ($batch->isComplete()) {
            return;
        }

        DB::transaction(function () use ($batch) {
            $shipmentIds = $batch->pickBatchShipments()->pluck('shipment_id');

            Shipment::whereIn('id', $shipmentIds)
                ->update(['picking_status' => PickingStatus::Pending]);

            $batch->update(['status' => PickBatchStatus::Cancelled]);
        });
    }

    /**
     * Mark all shipments in a batch as picked and complete the batch.
     */
    public function complete(PickBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $now = now();
            $shipmentIds = $batch->pickBatchShipments()->pluck('shipment_id');

            $batch->pickBatchShipments()
                ->whereNull('picked_at')
                ->update(['picked_at' => $now]);

            Shipment::whereKey($shipmentIds)
                ->update(['picking_status' => PickingStatus::Picked]);

            $batch->update([
                'status' => PickBatchStatus::Completed,
                'completed_at' => $now,
            ]);
        });
    }
}
