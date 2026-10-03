<?php

namespace App\Services;

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\PickBatchStatus;
use App\Enums\PickingStatus;
use App\Enums\ShipmentStatus;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use App\Models\User;
use DomainException;
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
    public function createFromShipments(Collection $shipments, User $user, ?int $clientId = null): ?PickBatch
    {
        $shipmentIds = $shipments->pluck('id')->filter()->values();

        if ($shipmentIds->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($shipmentIds, $user, $clientId) {
            $eligible = Shipment::query()
                ->whereKey($shipmentIds)
                ->where('picking_status', PickingStatus::Pending)
                ->where('status', ShipmentStatus::Open)
                ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (Shipment $shipment) => $shipmentIds->search($shipment->id))
                ->values();

            if ($eligible->isEmpty()) {
                return null;
            }

            $resolvedClientId = $this->resolveClientIdForBatch($eligible, $clientId);

            $batch = PickBatch::create([
                'user_id' => $user->id,
                'client_id' => $resolvedClientId,
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
        ?int $clientId = null,
    ): ?PickBatch {
        $query = Shipment::where('picking_status', PickingStatus::Pending)
            ->where('status', ShipmentStatus::Open)
            ->when($channelId, fn ($q) => $q->where('channel_id', $channelId))
            ->when($shippingMethodId, fn ($q) => $q->where('shipping_method_id', $shippingMethodId))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId));

        if ($prioritizeExpedited) {
            $query->leftJoin('shipping_methods', 'shipments.shipping_method_id', '=', 'shipping_methods.id')
                ->orderByDesc('shipping_methods.is_expedited')
                ->orderBy('shipments.created_at')
                ->select('shipments.*');
        } else {
            $query->orderBy('created_at');
        }

        $shipments = $query->limit($batchSize)->get();

        return $this->createFromShipments($shipments, $user, $clientId);
    }

    /**
     * @param  Collection<int, Shipment>  $eligible
     */
    private function resolveClientIdForBatch(Collection $eligible, ?int $clientId): int
    {
        if ($clientId !== null) {
            return $clientId;
        }

        $clientIds = $eligible->pluck('client_id')->filter()->unique()->values();

        if ($clientIds->count() !== 1) {
            throw new DomainException('Pick batches can only include shipments for one client.');
        }

        return (int) $clientIds->first();
    }

    /**
     * Cancel a pick batch and return its shipments to pending.
     */
    public function cancel(PickBatch $batch): void
    {
        if ($batch->isComplete()) {
            return;
        }

        DB::transaction(function () use ($batch): void {
            $shipmentIds = $batch->pickBatchShipments()->pluck('shipment_id');

            Shipment::whereIn('id', $shipmentIds)
                ->update(['picking_status' => PickingStatus::Pending]);

            $batch->update(['status' => PickBatchStatus::Cancelled]);
        });
    }

    /**
     * Remove a shipment from any in-progress pick batches, e.g. when it ships
     * before being picked, so pickers don't pull items for a completed order.
     * Batches left with no unpicked shipments are completed; emptied batches
     * are cancelled.
     */
    public function removeFromActiveBatches(Shipment $shipment): void
    {
        $pivots = PickBatchShipment::query()
            ->where('shipment_id', $shipment->id)
            ->whereNull('picked_at')
            ->whereHas('pickBatch', fn ($query) => $query->where('status', PickBatchStatus::InProgress))
            ->with('pickBatch')
            ->get();

        if ($pivots->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($shipment, $pivots): void {
            foreach ($pivots as $pivot) {
                $batch = $pivot->pickBatch;

                $pivot->delete();
                $batch->decrement('total_shipments');

                if ($batch->pickBatchShipments()->doesntExist()) {
                    $batch->update(['status' => PickBatchStatus::Cancelled]);
                } elseif ($batch->pickBatchShipments()->whereNull('picked_at')->doesntExist()) {
                    $this->complete($batch);
                }
            }

            if ($shipment->refresh()->picking_status === PickingStatus::Batched) {
                $shipment->update(['picking_status' => PickingStatus::Pending]);
            }
        });
    }

    /**
     * The batch's pack slips, in tote order, each carrying its tote code.
     */
    public function packSlipRun(PickBatch $batch): PackSlipRun
    {
        $rows = $batch->pickBatchShipments()
            ->whereNotNull('shipment_id')
            ->get(['shipment_id', 'tote_code'])
            ->sortBy('tote_code', SORT_NATURAL)
            ->values();

        return new PackSlipRun(
            shipmentIds: $rows->pluck('shipment_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all(),
            toteCodes: $rows->filter(fn (PickBatchShipment $row): bool => filled($row->tote_code))
                ->mapWithKeys(fn (PickBatchShipment $row): array => [(int) $row->shipment_id => (string) $row->tote_code])
                ->all(),
            title: "Pack Slips — Batch #{$batch->id}",
        );
    }

    /**
     * Build the aggregated product rows for the picking summary document.
     *
     * `totes` is a map of tote_code => quantity for that product in that tote,
     * sorted naturally by tote code.
     *
     * @return array<int, array{bin_location: string|null, sku: string, product_name: string, quantity: int, totes: array<string, int>}>
     */
    public function summaryRows(PickBatch $batch): array
    {
        $batch->load('pickBatchShipments.shipment.shipmentItems.product');

        $rows = collect();

        foreach ($batch->pickBatchShipments as $pivot) {
            foreach ($pivot->shipment->shipmentItems as $item) {
                $key = $item->product_id;

                if ($rows->has($key)) {
                    $row = $rows->get($key);
                    $row['quantity'] += $item->quantity;
                    $toteCode = $pivot->tote_code ?? '';
                    $row['totes'][$toteCode] = ($row['totes'][$toteCode] ?? 0) + $item->quantity;
                    $rows->put($key, $row);
                } else {
                    $toteCode = $pivot->tote_code ?? '';
                    $rows->put($key, [
                        'bin_location' => $item->product?->bin_location,
                        'sku' => $item->product?->sku ?? '—',
                        'product_name' => $item->product?->name ?? '—',
                        'quantity' => $item->quantity,
                        'totes' => [$toteCode => $item->quantity],
                    ]);
                }
            }
        }

        $rows = $rows->map(function (array $row): array {
            uksort($row['totes'], fn ($a, $b): int => strnatcasecmp($a, $b));

            return $row;
        });

        $located = $rows->filter(fn ($r): bool => filled($r['bin_location']))->values();
        $unlocated = $rows->filter(fn ($r): bool => blank($r['bin_location']))->values();

        $locatedArray = $located->toArray();
        usort($locatedArray, fn ($a, $b): int => strnatcasecmp($a['bin_location'], $b['bin_location']));

        $unlocatedArray = $unlocated->toArray();
        usort($unlocatedArray, fn ($a, $b): int => strcasecmp($a['product_name'], $b['product_name']));

        return array_merge($locatedArray, $unlocatedArray);
    }

    /**
     * Mark all shipments in a batch as picked and complete the batch.
     */
    public function complete(PickBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
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
