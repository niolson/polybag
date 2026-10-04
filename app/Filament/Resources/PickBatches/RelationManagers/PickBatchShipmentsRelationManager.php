<?php

namespace App\Filament\Resources\PickBatches\RelationManagers;

use App\Filament\Resources\ShipmentResource;
use App\Models\Location;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class PickBatchShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'pickBatchShipments';

    protected static ?string $title = 'Shipments';

    /**
     * Re-render the table when the batch is changed by the parent page (e.g. "Mark All Picked"),
     * or when the browser records its pack slips as printed, either of which otherwise leaves
     * this nested Livewire component showing stale rows.
     */
    #[On('pick-batch-updated')]
    #[On('pack-slips-printed')]
    public function refreshShipments(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['shipment']))
            ->columns([
                Tables\Columns\TextColumn::make('tote_code')
                    ->label('Tote')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Reference')
                    ->url(fn ($record): ?string => $record->shipment_id
                        ? ShipmentResource::getUrl('view', ['record' => $record->shipment_id])
                        : null),
                Tables\Columns\TextColumn::make('shipment.first_name')
                    ->label('Name')
                    ->formatStateUsing(fn ($record): string => trim(($record->shipment?->first_name ?? '').' '.($record->shipment?->last_name ?? ''))),
                Tables\Columns\TextColumn::make('shipment.city')
                    ->label('City')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('picked_at')
                    ->label('Picked')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->trueColor('success')
                    ->falseIcon('heroicon-o-clock')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('picked_at')
                    ->label('Picked At')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('shipment.pack_slip_printed_at')
                    ->label('Slip Printed')
                    ->boolean()
                    ->trueIcon('heroicon-o-printer')
                    ->trueColor('success')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->falseColor('gray')
                    ->visible(fn (): bool => app(SettingsService::class)->packSlipsEnabled()),
                Tables\Columns\TextColumn::make('shipment.pack_slip_printed_at')
                    ->label('Slip Printed At')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => app(SettingsService::class)->packSlipsEnabled()),
            ])
            ->recordActions([
                Action::make('markPicked')
                    ->label('Mark Picked')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record): bool => $record->picked_at === null)
                    ->action(function ($record): void {
                        $record->update(['picked_at' => now()]);

                        $batch = $record->pickBatch;
                        $allPicked = $batch->pickBatchShipments()->whereNull('picked_at')->doesntExist();

                        if ($allPicked) {
                            app(PickBatchService::class)->complete($batch);
                            Notification::make()->success()->title('All items picked — batch completed.')->send();
                        } else {
                            Notification::make()->success()->title('Marked as picked.')->send();
                        }

                        $this->dispatch('pick-batch-updated');
                    }),
            ])
            ->defaultSort('tote_code', 'asc');
    }
}
