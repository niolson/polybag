<?php

namespace App\Filament\Resources\PickBatches\Pages;

use App\DataTransferObjects\PackSlips\PackSlipPrintJob;
use App\Enums\PickBatchStatus;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Models\PickBatch;
use App\Services\GotenbergService;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\PickBatchService;
use App\Services\SettingsService;
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
                    try {
                        $summary = $this->summaryPdf();
                    } catch (Throwable $e) {
                        $this->notifyRendererUnavailable($e);

                        return;
                    }

                    $this->dispatch('print-report', data: $summary);
                    $this->record->update(['summary_printed_at' => now()]);
                }),

            // View mode — open HTML in a new tab
            Action::make('viewPackSlips')
                ->label('Pack Slips')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => ! $this->printMode && self::printsPackSlips())
                ->url(fn (): string => route('pick-batches.pack-slips', $this->record))
                ->openUrlInNewTab(),

            // Print mode — render PDF via Gotenberg and send to report printer
            Action::make('printPackSlips')
                ->label('Pack Slips')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->printMode && self::printsPackSlips())
                ->action(function (): void {
                    try {
                        $jobs = $this->packSlipJobs();
                    } catch (Throwable $e) {
                        $this->notifyRendererUnavailable($e);

                        return;
                    }

                    // Recorded on each Shipment only when the browser redeems a job's
                    // receipt, once QZ Tray reports that job sent.
                    $this->dispatch('print-pack-slips', jobs: $jobs);
                }),

            // Print mode — the summary to the document printer, then the slips to the
            // label printer, as one browser event so the slips wait on the summary.
            Action::make('printBoth')
                ->label('Print Both')
                ->icon('heroicon-o-printer')
                ->visible(fn (): bool => $this->printMode && self::printsPackSlips())
                ->action(function (): void {
                    try {
                        $summary = $this->summaryPdf();
                        $jobs = $this->packSlipJobs();
                    } catch (Throwable $e) {
                        $this->notifyRendererUnavailable($e);

                        return;
                    }

                    $this->dispatch('print-pick-batch', summary: $summary, jobs: $jobs);
                    $this->record->update(['summary_printed_at' => now()]);
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
     * The picking summary as a base64 PDF for the document printer.
     */
    private function summaryPdf(): string
    {
        return base64_encode(app(GotenbergService::class)->pdfFromView('pick-batches.summary', [
            'pickBatch' => $this->record,
            'rows' => app(PickBatchService::class)->summaryRows($this->pickBatch()),
        ]));
    }

    /**
     * With pack slips off, a batch offers only its Picking Summary.
     */
    private static function printsPackSlips(): bool
    {
        return app(SettingsService::class)->packSlipsEnabled();
    }

    /**
     * The batch's pack slips in tote order, as receipt-carrying print jobs for the browser.
     *
     * @return list<array{data: string, receipt: string, count: int}>
     */
    private function packSlipJobs(): array
    {
        return array_map(
            fn (PackSlipPrintJob $job): array => $job->toBrowserPayload(),
            app(PackSlipRenderer::class)->printJobs(
                app(PickBatchService::class)->packSlipRun($this->pickBatch()),
                auth()->user(),
            ),
        );
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
