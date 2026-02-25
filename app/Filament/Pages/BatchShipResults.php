<?php

namespace App\Filament\Pages;

use App\Enums\LabelBatchItemStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\LabelBatch;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Bus;

class BatchShipResults extends Page
{
    use NotifiesUser;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'batch-ship/{labelBatchId}';

    protected string $view = 'filament.pages.batch-ship-results';

    public ?LabelBatch $labelBatch = null;

    public array $items = [];

    public int $progressPercent = 0;

    public bool $isComplete = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Admin) ?? false;
    }

    public function mount(int $labelBatchId): void
    {
        $this->labelBatch = LabelBatch::with(['boxSize', 'user'])->findOrFail($labelBatchId);

        // Owner check
        if ($this->labelBatch->user_id !== auth()->id() && ! auth()->user()->role->isAtLeast(Role::Admin)) {
            abort(403);
        }

        $this->refreshBatchStatus();
    }

    public function getTitle(): string
    {
        return 'Batch Ship Results';
    }

    public function refreshBatchStatus(): void
    {
        $this->labelBatch->refresh();

        $this->items = $this->labelBatch->items()
            ->with('shipment')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'reference' => $item->shipment->shipment_reference,
                'name' => trim($item->shipment->first_name.' '.$item->shipment->last_name),
                'status' => $item->status,
                'tracking_number' => $item->tracking_number,
                'carrier' => $item->carrier,
                'service' => $item->service,
                'cost' => $item->cost,
                'error_message' => $item->error_message,
            ])
            ->toArray();

        // Calculate progress from Bus::batch if available
        if ($this->labelBatch->bus_batch_id) {
            $busBatch = Bus::findBatch($this->labelBatch->bus_batch_id);
            $this->progressPercent = $busBatch ? $busBatch->progress() : 0;
        } else {
            $processed = $this->labelBatch->successful_shipments + $this->labelBatch->failed_shipments;
            $this->progressPercent = $this->labelBatch->total_shipments > 0
                ? (int) round(($processed / $this->labelBatch->total_shipments) * 100)
                : 0;
        }

        $this->isComplete = $this->labelBatch->isComplete();
    }

    public function printAllLabels(): void
    {
        $labels = $this->labelBatch->items()
            ->where('status', LabelBatchItemStatus::Success)
            ->with('package')
            ->get()
            ->filter(fn ($item) => $item->package && $item->package->label_data)
            ->map(fn ($item) => [
                'label' => $item->package->label_data,
                'orientation' => $item->package->label_orientation ?? 'portrait',
                'format' => $item->package->label_format ?? $this->labelBatch->label_format,
                'dpi' => $item->package->label_dpi ?? $this->labelBatch->label_dpi,
            ])
            ->values()
            ->toArray();

        if (empty($labels)) {
            $this->notifyWarning('No Labels', 'No printable labels found.');

            return;
        }

        $this->dispatch('print-batch-labels', labels: $labels);
    }
}
