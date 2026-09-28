<?php

namespace App\Jobs;

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Models\LabelBatchItem;
use App\Services\PostageSources\OfferStore;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateLabelJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 2;

    /** @var int[] */
    public array $backoff = [10, 30];

    public function __construct(
        public int $labelBatchItemId,
        public string $labelFormat,
        public ?int $labelDpi,
        public bool $hasReportPrinter = false,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $item = LabelBatchItem::find($this->labelBatchItemId);

        if (! $item) {
            return;
        }

        $item->update(['status' => LabelBatchItemStatus::Processing]);

        try {
            $result = app(PackageShippingWorkflow::class)->autoShip(
                $item->package,
                new PackageAutoShippingRequest(
                    labelFormat: $this->labelFormat,
                    labelDpi: $this->labelDpi,
                    userId: $item->labelBatch->user_id,
                    cleanupOnFailure: false,
                    hasReportPrinter: $this->hasReportPrinter,
                ),
            );

            if ($result->success && $result->response) {
                $item->update([
                    'status' => LabelBatchItemStatus::Success,
                    'tracking_number' => $result->response->trackingNumber,
                    'carrier' => $result->response->carrier,
                    'service' => $result->response->service,
                    'cost' => $result->response->cost,
                ]);

                $item->labelBatch->increment('successful_shipments');
                $item->labelBatch->increment('total_cost', $result->response->cost ?? 0);
            } else {
                $this->handleFailure($item, $result->summaryMessage());
            }
        } catch (Throwable $e) {
            $this->handleFailure($item, $e->getMessage());
        }
    }

    private function handleFailure(LabelBatchItem $item, string $errorMessage): void
    {
        // A purchase that never reported back may have bought a label, and the
        // offer recording it cascades with the package. Keep both, so the
        // shipment stays out of the next batch and the Ship page asks the
        // carrier before anything is bought again (`project-review/01`).
        if ($item->package && app(OfferStore::class)->hasUnresolvedPurchase($item->package)) {
            $errorMessage .= ' A label may already exist for this package: open it on the Ship page, which checks with the carrier before buying again.';
        } elseif ($item->package && $item->package->status !== PackageStatus::Shipped) {
            // Clean up the unshipped package
            $item->package->packageItems()->delete();
            $item->package->delete();
            $item->update(['package_id' => null]);
        }

        $item->update([
            'status' => LabelBatchItemStatus::Failed,
            'error_message' => $errorMessage,
        ]);

        $item->labelBatch->increment('failed_shipments');
    }
}
