<?php

namespace App\Filament\Resources\LabelBatchResource\Pages;

use App\Enums\LabelBatchItemStatus;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Resources\LabelBatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewLabelBatch extends ViewRecord
{
    use NotifiesUser;

    protected static string $resource = LabelBatchResource::class;

    protected string $view = 'filament.resources.label-batch-resource.pages.view-label-batch';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printAllLabels')
                ->label(fn () => 'Print All Labels ('.$this->record->successful_shipments.')')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->visible(fn () => $this->record->isComplete() && $this->record->successful_shipments > 0)
                ->action(function (): void {
                    $labels = $this->record->items()
                        ->where('status', LabelBatchItemStatus::Success)
                        ->with('package')
                        ->get()
                        ->filter(fn ($item) => $item->package && $item->package->label_data)
                        ->map(fn ($item) => [
                            'label' => $item->package->label_data,
                            'orientation' => $item->package->label_orientation ?? 'portrait',
                            'format' => $item->package->label_format ?? $this->record->label_format,
                            'dpi' => $item->package->label_dpi ?? $this->record->label_dpi,
                        ])
                        ->values()
                        ->toArray();

                    if (empty($labels)) {
                        $this->notifyWarning('No Labels', 'No printable labels found.');

                        return;
                    }

                    $this->dispatch('print-batch-labels', labels: $labels);
                }),
            Action::make('backToShipments')
                ->label('Back to Shipments')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url('/shipments'),
        ];
    }
}
