<?php

namespace App\Jobs;

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Models\LabelBatchItem;
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
        // Clean up the unshipped package
        if ($item->package && $item->package->status !== PackageStatus::Shipped) {
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
