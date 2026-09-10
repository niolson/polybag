<?php

namespace App\Filament\Resources\LabelBatchResource\Pages;

use App\Filament\Concerns\NotifiesUser;
use App\Filament\Resources\LabelBatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Bus;
use Livewire\Attributes\On;

class ViewLabelBatch extends ViewRecord
{
    use NotifiesUser;

    protected static string $resource = LabelBatchResource::class;

    protected string $view = 'filament.resources.label-batch-resource.pages.view-label-batch';

    /**
     * Re-render once the browser finishes a batch print run, so the printed counts
     * and the "Print Labels (N)" action reflect what was just acknowledged.
     */
    #[On('batch-print-finished')]
    public function refreshPrintedCounts(): void
    {
        $this->record->refresh();
    }

    public function getProgressPercent(): int
    {
        if ($this->record->bus_batch_id) {
            $busBatch = Bus::findBatch($this->record->bus_batch_id);

            return $busBatch ? $busBatch->progress() : 0;
        }

        $processed = $this->record->successful_shipments + $this->record->failed_shipments;

        return $this->record->total_shipments > 0
            ? (int) round(($processed / $this->record->total_shipments) * 100)
            : 0;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printUnprintedLabels')
                ->label(fn (): string => 'Print Labels ('.$this->record->unprintedCount().')')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->visible(fn (): bool => $this->record->isComplete() && $this->record->unprintedCount() > 0)
                ->action(fn () => $this->dispatchBatchPrint(unprintedOnly: true)),
            Action::make('reprintAllLabels')
                ->label(fn (): string => 'Reprint All ('.$this->record->printableItems()->count().')')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Reprint every label in this batch')
                ->modalDescription(fn (): string => 'This reprints all '.$this->record->printableItems()->count()
                    .' labels, including ones already printed.')
                ->visible(fn (): bool => $this->record->isComplete() && $this->record->printableItems()->count() > 0)
                ->action(fn () => $this->dispatchBatchPrint(unprintedOnly: false)),
            Action::make('backToShipments')
                ->label('Back to Shipments')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url('/shipments'),
        ];
    }

    /**
     * Send this batch's labels to QZ Tray. Each label carries its package id so the
     * browser can report the print back as it goes — if the operator closes the tab
     * partway through, the labels that did print stay marked and "Print Labels"
     * picks up from where it stopped.
     */
    private function dispatchBatchPrint(bool $unprintedOnly): void
    {
        $items = $unprintedOnly
            ? $this->record->unprintedItems()->with('package')->get()
            : $this->record->printableItems()->with('package')->get();

        $labels = $items
            ->map(fn ($item): array => [
                'label' => $item->package->label_data,
                'orientation' => $item->package->label_orientation ?? 'portrait',
                'format' => $item->package->label_format ?? $this->record->label_format,
                'dpi' => $item->package->label_dpi ?? $this->record->label_dpi,
                'packageId' => $item->package->id,
                // Printed to the report printer straight after its own label, so
                // the paperwork stays with the parcel it belongs to rather than
                // arriving as an unordered stack at the end of the run.
                'customsForm' => $item->package->customs_form_data,
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
