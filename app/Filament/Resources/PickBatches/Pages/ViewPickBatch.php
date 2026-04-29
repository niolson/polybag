<?php

namespace App\Filament\Resources\PickBatches\Pages;

use App\Enums\PickBatchStatus;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Services\PickBatchService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPickBatch extends ViewRecord
{
    protected static string $resource = PickBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printSummary')
                ->label('Picking Summary')
                ->icon('heroicon-o-list-bullet')
                ->url(fn () => route('pick-batches.summary', $this->record))
                ->openUrlInNewTab(),

            Action::make('printPackSlips')
                ->label('Pack Slips')
                ->icon('heroicon-o-document-text')
                ->url(fn () => route('pick-batches.pack-slips', $this->record))
                ->openUrlInNewTab(),

            Action::make('complete')
                ->label('Mark All Picked')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === PickBatchStatus::InProgress)
                ->requiresConfirmation()
                ->modalHeading('Mark Batch as Picked')
                ->modalDescription('This will mark all shipments in this batch as picked and complete the batch.')
                ->action(function (): void {
                    app(PickBatchService::class)->complete($this->record);

                    Notification::make()->success()->title('Batch marked as picked.')->send();

                    $this->record->refresh();
                }),

            Action::make('cancel')
                ->label('Cancel Batch')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn () => $this->record->status === PickBatchStatus::InProgress)
                ->requiresConfirmation()
                ->modalHeading('Cancel Pick Batch')
                ->modalDescription('This will cancel the batch and return all shipments to pending picking status.')
                ->action(function (): void {
                    app(PickBatchService::class)->cancel($this->record);

                    Notification::make()->success()->title('Pick batch cancelled.')->send();

                    $this->record->refresh();
                }),
        ];
    }
}
