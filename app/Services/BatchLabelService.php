<?php

namespace App\Services;

use App\DataTransferObjects\BatchValidationResult;
use App\Enums\AuditAction;
use App\Enums\LabelBatchItemStatus;
use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Jobs\GenerateLabelJob;
use App\Models\AuditLog;
use App\Models\BoxSize;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\BatchLabelCompleted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class BatchLabelService
{
    /**
     * @param  Collection<int, Shipment>  $shipments
     */
    public function validateShipmentsForBatch(Collection $shipments): BatchValidationResult
    {
        $eligible = collect();
        $ineligible = collect();

        $shipments->each(fn (Shipment $s) => $s->loadMissing(['shipmentItems.product', 'packages']));

        foreach ($shipments as $shipment) {
            $reason = $this->getIneligibilityReason($shipment);

            if ($reason) {
                $ineligible->push(['shipment' => $shipment, 'reason' => $reason]);
            } else {
                $eligible->push($shipment);
            }
        }

        return new BatchValidationResult($eligible, $ineligible);
    }

    private function getIneligibilityReason(Shipment $shipment): ?string
    {
        if ($shipment->status === ShipmentStatus::Shipped) {
            return 'Already shipped';
        }

        if (! $shipment->shipping_method_id) {
            return 'No shipping method assigned';
        }

        if (! $shipment->address1 || ! $shipment->city || ! $shipment->country) {
            return 'Missing address fields';
        }

        if ($shipment->packages->where('status', PackageStatus::Unshipped)->isNotEmpty()) {
            return 'Has existing unshipped packages';
        }

        if ($shipment->shipmentItems->isEmpty()) {
            return 'No items';
        }

        foreach ($shipment->shipmentItems as $item) {
            if (! $item->product || $item->product->weight <= 0) {
                return 'Item missing product weight: '.($item->product?->sku ?? 'unknown');
            }

            if ($item->transparency) {
                return 'Contains transparency-required items';
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Shipment>  $shipments  Already validated as eligible
     */
    public function createBatch(
        Collection $shipments,
        BoxSize $boxSize,
        User $user,
        string $labelFormat,
        ?int $labelDpi,
    ): LabelBatch {
        return DB::transaction(function () use ($shipments, $boxSize, $user, $labelFormat, $labelDpi) {
            $batch = LabelBatch::create([
                'user_id' => $user->id,
                'box_size_id' => $boxSize->id,
                'label_format' => $labelFormat,
                'label_dpi' => $labelDpi,
                'status' => LabelBatchStatus::Pending,
                'total_shipments' => $shipments->count(),
            ]);

            $jobs = [];

            foreach ($shipments as $shipment) {
                $itemsWeight = $shipment->shipmentItems->sum(
                    fn ($item) => $item->quantity * $item->product->weight
                );
                $totalWeight = $boxSize->empty_weight + $itemsWeight;

                $package = Package::create([
                    'shipment_id' => $shipment->id,
                    'box_size_id' => $boxSize->id,
                    'weight' => $totalWeight,
                    'height' => $boxSize->height,
                    'width' => $boxSize->width,
                    'length' => $boxSize->length,
                ]);

                foreach ($shipment->shipmentItems as $shipmentItem) {
                    PackageItem::create([
                        'package_id' => $package->id,
                        'shipment_item_id' => $shipmentItem->id,
                        'product_id' => $shipmentItem->product_id,
                        'quantity' => $shipmentItem->quantity,
                    ]);
                }

                $batchItem = LabelBatchItem::create([
                    'label_batch_id' => $batch->id,
                    'shipment_id' => $shipment->id,
                    'package_id' => $package->id,
                    'status' => LabelBatchItemStatus::Pending,
                ]);

                $jobs[] = new GenerateLabelJob($batchItem->id, $labelFormat, $labelDpi);
            }

            $busBatch = Bus::batch($jobs)
                ->allowFailures()
                ->before(function () use ($batch) {
                    $batch->update([
                        'status' => LabelBatchStatus::Processing,
                        'started_at' => now(),
                    ]);
                })
                ->finally(function () use ($batch) {
                    $batch->refresh();

                    $status = match (true) {
                        $batch->failed_shipments === 0 => LabelBatchStatus::Completed,
                        $batch->successful_shipments === 0 => LabelBatchStatus::Failed,
                        default => LabelBatchStatus::CompletedWithErrors,
                    };

                    $batch->update([
                        'status' => $status,
                        'completed_at' => now(),
                    ]);

                    $user = User::find($batch->user_id);
                    $user?->notify(new BatchLabelCompleted($batch));
                })
                ->dispatch();

            $batch->update(['bus_batch_id' => $busBatch->id]);

            AuditLog::record(
                AuditAction::BatchStarted,
                $batch,
                metadata: [
                    'total_shipments' => $batch->total_shipments,
                    'box_size' => $boxSize->label,
                    'label_format' => $labelFormat,
                ],
            );

            return $batch;
        });
    }
}
