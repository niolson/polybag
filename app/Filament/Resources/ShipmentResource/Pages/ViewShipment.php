<?php

namespace App\Filament\Resources\ShipmentResource\Pages;

use App\DataTransferObjects\PackSlips\PackSlipPrintJob;
use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Enums\Deliverability;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Services\PackSlips\PackSlipRenderer;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;
use LogicException;
use Throwable;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    protected string $view = 'filament.resources.shipment-resource.pages.view-shipment';

    /**
     * Pick up the printed time and user once QZ Tray's acknowledgment lands.
     */
    #[On('pack-slips-printed')]
    public function refreshPackSlip(): void
    {
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('validateAddress')
                ->label('Validate Address')
                ->icon('heroicon-o-shield-check')
                ->color('success')
                ->action(function (): void {
                    $this->record->validateAddress();
                    $this->record->refresh();

                    if ($this->record->deliverability === Deliverability::NotChecked) {
                        Notification::make()
                            ->title('Address not checked')
                            ->body('No address validator available for this country.')
                            ->info()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Address validated')
                            ->body($this->record->validation_message ?? 'Validation complete')
                            ->success()
                            ->send();
                    }
                }),
            Actions\Action::make('pack')
                ->label('Pack')
                ->icon('heroicon-o-archive-box')
                ->color('primary')
                ->url(fn (): string => '/pack/'.$this->record->id),
            Actions\Action::make('printPackSlip')
                ->label(fn (): string => $this->shipment()->hasPrintedPackSlip() ? 'Reprint Pack Slip' : 'Print Pack Slip')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => $this->shipment()->status === ShipmentStatus::Open)
                ->authorize(fn (): bool => auth()->user()->can('printPackSlip', $this->shipment()))
                ->requiresConfirmation(fn (): bool => $this->shipment()->activePickBatch() !== null)
                ->modalHeading('Print without the tote code?')
                ->modalDescription(fn (): string => 'This Shipment is in pick batch #'.$this->shipment()->activePickBatch()?->id
                    .'. A slip printed here has no tote code; print the batch\'s pack slips for one that does.')
                ->modalSubmitActionLabel('Print without tote')
                ->action(function (): void {
                    try {
                        $jobs = app(PackSlipRenderer::class)->printJobs(PackSlipRun::forShipment($this->shipment()->id), auth()->user());
                    } catch (Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('PDF renderer unavailable')
                            ->body($e->getMessage())
                            ->send();

                        return;
                    }

                    $this->dispatch('print-pack-slips', jobs: array_map(
                        fn (PackSlipPrintJob $job): array => $job->toBrowserPayload(),
                        $jobs,
                    ));
                }),
            Actions\Action::make('viewPackSlip')
                ->label('View Pack Slip')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->authorize(fn (): bool => auth()->user()->can('printPackSlip', $this->shipment()))
                ->url(fn (): string => route('shipments.pack-slip', $this->shipment()))
                ->openUrlInNewTab(),
            Actions\EditAction::make(),
        ];
    }

    private function shipment(): Shipment
    {
        $record = $this->getRecord();

        if (! $record instanceof Shipment) {
            throw new LogicException('View Shipment was opened for a record that is not a Shipment.');
        }

        return $record;
    }
}
