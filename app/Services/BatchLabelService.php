<?php

namespace App\Services;

use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PostageOfferSource;
use App\DataTransferObjects\BatchValidationResult;
use App\DataTransferObjects\PackageDrafts\BatchPackageDraftInput;
use App\DataTransferObjects\Shipping\AddressData;
use App\Enums\AuditAction;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\LabelBatchItemStatus;
use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Jobs\GenerateLabelJob;
use App\Models\AuditLog;
use App\Models\BoxSize;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Location;
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
     * @param  bool  $hasReportPrinter  Whether the workstation starting the batch has a report printer — browser state, pushed with the label format
     */
    public function validateShipmentsForBatch(Collection $shipments, bool $hasReportPrinter = false): BatchValidationResult
    {
        $eligible = collect();
        $ineligible = collect();

        $shipments->each(fn (Shipment $s) => $s->loadMissing(['shipmentItems.product', 'packages', 'location']));

        foreach ($shipments as $shipment) {
            $reason = $this->getIneligibilityReason($shipment, $hasReportPrinter);

            if ($reason) {
                $ineligible->push(['shipment' => $shipment, 'reason' => $reason]);
            } else {
                $eligible->push($shipment);
            }
        }

        return new BatchValidationResult($eligible, $ineligible);
    }

    private function getIneligibilityReason(Shipment $shipment, bool $hasReportPrinter): ?string
    {
        if ($shipment->status === ShipmentStatus::Shipped) {
            return 'Already shipped';
        }

        if ($shipment->status === ShipmentStatus::Cancelled) {
            return 'Shipment cancelled';
        }

        if ($shipment->isAmazonFulfilled()) {
            return 'Fulfilled by Amazon (FBA)';
        }

        if ($shipment->isBlockedByPicking()) {
            return 'Not picked yet';
        }

        if ($shipment->needsShippingMethod()) {
            return 'No shipping method assigned';
        }

        if (! $shipment->address1 || ! $shipment->city || ! $shipment->country) {
            return 'Missing address fields';
        }

        if ($shipment->packages->where('status', PackageStatus::Unshipped)->isNotEmpty()) {
            return 'Has a package draft in progress';
        }

        // Batch ship packs the whole order into one box, so an order another
        // Package has partly sent is a person's to finish on the Pack page.
        if ($shipment->packages->where('status', PackageStatus::Shipped)->isNotEmpty()) {
            return 'Partly shipped';
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

        if (! $hasReportPrinter && $this->everyCarrierReturnsASeparateCustomsDocument($shipment)) {
            return 'No document printer configured for the customs form';
        }

        return null;
    }

    /**
     * Whether this batch cannot buy anything for the shipment without a report
     * printer — `shopify-shipping-carrier/07` constraint 4, the skip beside
     * "Not picked yet".
     *
     * Asked of every carrier the shipping method could rate-shop, because the
     * batch has not chosen one yet: a method that offers USPS beside UPS can
     * still buy USPS, whose CP72 prints on the label printer, so it is not
     * skipped here. It is skipped only when every carrier on the method
     * answers {@see CustomsDocumentDelivery::Separate} for this
     * lane — when the batch would be starting a purchase that
     * `EloquentPackageShippingWorkflow` is certain to refuse. That purchase-time
     * check still stands behind this one, with the rate in hand.
     *
     * "Could rate-shop" is rate shopping's own answer,
     * {@see ShippingRateService::sellersForShipment()}, so an unconfigured
     * carrier, one whose services cannot reach this destination, or a channel
     * the shipment did not come from does not count as a way out that the
     * batch does not have.
     */
    private function everyCarrierReturnsASeparateCustomsDocument(Shipment $shipment): bool
    {
        // No origin means no lane to ask about. Validation reports reasons
        // rather than throwing; the purchase will name the missing location.
        $origin = $shipment->location ?? Location::getDefault();

        if ($origin === null) {
            return false;
        }

        $from = AddressData::fromLocation($origin);
        $to = AddressData::fromShipment($shipment);

        if ($from->sharesCustomsZoneWith($to)) {
            return false;
        }

        $sellers = app(ShippingRateService::class)->sellersForShipment($shipment, $origin->id, $to);

        return $sellers->isNotEmpty()
            && $sellers->every(fn (PostageOfferSource $seller): bool => $seller->customsDocumentDelivery($from, $to)->needsReportPrinter());
    }

    /**
     * @param  Collection<int, Shipment>  $shipments  Already validated as eligible
     */
    /**
     * Finish the batch once every item has settled, and tell the user who
     * started it — once, whichever caller gets here first.
     *
     * Called from the bus batch's `finally`, and from a label job's `failed()`.
     * Laravel runs `finally` for a job that died *before* that job's
     * `failed()` records its item, so `finally` alone would finish the batch
     * on stale counts (`import-memory/02`).
     *
     * The counts are read after every item is seen settled, which is right
     * only because a label job commits an item's status and its count in one
     * transaction: an item seen settled has its count committed too.
     */
    public function finishIfSettled(LabelBatch $batch): void
    {
        $unsettled = $batch->items()
            ->whereIn('status', [LabelBatchItemStatus::Pending, LabelBatchItemStatus::Processing])
            ->exists();

        if ($unsettled) {
            return;
        }

        $batch->refresh();

        $status = match (true) {
            $batch->failed_shipments === 0 => LabelBatchStatus::Completed,
            $batch->successful_shipments === 0 => LabelBatchStatus::Failed,
            default => LabelBatchStatus::CompletedWithErrors,
        };

        $finished = LabelBatch::query()
            ->whereKey($batch->id)
            ->whereNotIn('status', [LabelBatchStatus::Completed, LabelBatchStatus::CompletedWithErrors, LabelBatchStatus::Failed])
            ->update(['status' => $status, 'completed_at' => now()]);

        if ($finished !== 1) {
            return;
        }

        $batch->refresh();
        User::find($batch->user_id)?->notify(new BatchLabelCompleted($batch));
    }

    public function createBatch(
        Collection $shipments,
        BoxSize $boxSize,
        User $user,
        string $labelFormat,
        ?int $labelDpi,
        bool $hasReportPrinter = false,
    ): LabelBatch {
        // The rows commit before any job is queued: a worker that picked a job
        // up first would find no batch item, do nothing, and leave the batch
        // processing for good (the queue connection does not wait for commits).
        [$batch, $jobs] = DB::transaction(function () use ($shipments, $boxSize, $user, $labelFormat, $labelDpi, $hasReportPrinter): array {
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
                $readyDraft = app(PackageDraftWorkflow::class)->createBatchReadyDraft(
                    $shipment,
                    new BatchPackageDraftInput($boxSize),
                );

                $batchItem = LabelBatchItem::create([
                    'label_batch_id' => $batch->id,
                    'shipment_id' => $shipment->id,
                    'package_id' => $readyDraft->package->id,
                    'status' => LabelBatchItemStatus::Pending,
                ]);

                $jobs[] = new GenerateLabelJob($batchItem->id, $labelFormat, $labelDpi, $hasReportPrinter);
            }

            return [$batch, $jobs];
        });

        $busBatch = Bus::batch($jobs)
            ->allowFailures()
            ->before(function () use ($batch): void {
                $batch->update([
                    'status' => LabelBatchStatus::Processing,
                    'started_at' => now(),
                ]);
            })
            ->finally(function () use ($batch): void {
                app(self::class)->finishIfSettled($batch);
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
    }
}
