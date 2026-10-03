<?php

namespace App\Filament\Resources\PickBatches\Pages;

use App\Enums\PickBatchStatus;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Models\PickBatch;
use App\Services\GotenbergService;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\PickBatchService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;
use LogicException;
use Throwable;

class ViewPickBatch extends ViewRecord
{
    protected static string $resource = PickBatchResource::class;

    protected string $view = 'filament.resources.pick-batch-resource.pages.view-pick-batch';

    public bool $printMode = false;

    /**
     * Pull fresh batch state when a nested component (the shipments relation manager)
     * completes the batch, so the header actions and detail view stop showing stale status.
     */
    #[On('pick-batch-updated')]
    public function refreshBatch(): void
    {
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('togglePrintMode')
                ->label(fn (): string => $this->printMode ? 'Print' : 'View')
                ->icon(fn (): string => $this->printMode ? 'heroicon-o-printer' : 'heroicon-o-eye')
                ->color('gray')
                ->outlined(fn (): bool => ! $this->printMode)
                ->action(fn (): bool => $this->printMode = ! $this->printMode),

            // View mode — open HTML in a new tab
            Action::make('viewSummary')
                ->label('Picking Summary')
                ->icon('heroicon-o-list-bullet')
                ->visible(fn (): bool => ! $this->printMode)
                ->url(fn (): string => route('pick-batches.summary', $this->record))
                ->openUrlInNewTab(),

            // Print mode — render PDF via Gotenberg and send to report printer
            Action::make('printSummary')
                ->label('Picking Summary')
                ->icon('heroicon-o-list-bullet')
                ->visible(fn (): bool => $this->printMode)
                ->action(function (): void {
                    $this->printDocument('pick-batches.summary', [
                        'pickBatch' => $this->record,
                        'rows' => app(PickBatchService::class)->summaryRows($this->record),
                    ], function (): void {
                        $this->record->update(['summary_printed_at' => now()]);
                    });
                }),

            // View mode — open HTML in a new tab
            Action::make('viewPackSlips')
                ->label('Pack Slips')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => ! $this->printMode)
                ->url(fn (): string => route('pick-batches.pack-slips', $this->record))
                ->openUrlInNewTab(),

            // Print mode — render PDF via Gotenberg and send to report printer
            Action::make('printPackSlips')
                ->label('Pack Slips')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->printMode)
                ->action(function (): void {
                    try {
                        $pdf = app(PackSlipRenderer::class)->pdf(app(PickBatchService::class)->packSlipRun($this->pickBatch()));
                    } catch (Throwable $e) {
                        $this->notifyRendererUnavailable($e);

                        return;
                    }

                    $this->dispatch('print-report', data: base64_encode($pdf));
                    $this->record->pickBatchShipments()->update(['pack_slip_printed_at' => now()]);
                }),

            Action::make('complete')
                ->label('Mark All Picked')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === PickBatchStatus::InProgress)
                ->requiresConfirmation()
                ->modalHeading('Mark Batch as Picked')
                ->modalDescription('This will mark all shipments in this batch as picked and complete the batch.')
                ->action(function (): void {
                    app(PickBatchService::class)->complete($this->record);

                    Notification::make()->success()->title('Batch marked as picked.')->send();

                    $this->record->refresh();
                    $this->dispatch('pick-batch-updated');
                }),

            Action::make('cancel')
                ->label('Cancel Batch')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === PickBatchStatus::InProgress)
                ->requiresConfirmation()
                ->modalHeading('Cancel Pick Batch')
                ->modalDescription('This will cancel the batch and return all shipments to pending picking status.')
                ->action(function (): void {
                    app(PickBatchService::class)->cancel($this->record);

                    Notification::make()->success()->title('Pick batch cancelled.')->send();

                    $this->record->refresh();
                    $this->dispatch('pick-batch-updated');
                }),
        ];
    }

    private function pickBatch(): PickBatch
    {
        $record = $this->getRecord();

        if (! $record instanceof PickBatch) {
            throw new LogicException('View Pick Batch was opened for a record that is not a pick batch.');
        }

        return $record;
    }

    /**
     * Render a view to PDF via Gotenberg, dispatch to QZ Tray, and call $onSuccess if it worked.
     *
     * @param  array<string, mixed>  $data
     */
    private function printDocument(string $view, array $data, callable $onSuccess): void
    {
        try {
            $pdf = app(GotenbergService::class)->pdfFromView($view, $data);
            $this->dispatch('print-report', data: base64_encode($pdf));
            $onSuccess();
        } catch (Throwable $e) {
            $this->notifyRendererUnavailable($e);
        }
    }

    private function notifyRendererUnavailable(Throwable $e): void
    {
        Notification::make()
            ->danger()
            ->title('PDF renderer unavailable')
            ->body($e->getMessage())
            ->send();
    }
}
