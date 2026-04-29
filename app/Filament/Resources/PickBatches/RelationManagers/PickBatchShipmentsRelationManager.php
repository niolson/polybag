<?php

namespace App\Filament\Resources\PickBatches\RelationManagers;

use App\Filament\Resources\ShipmentResource;
use App\Models\Location;
use App\Services\PickBatchService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PickBatchShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'pickBatchShipments';

    protected static ?string $title = 'Shipments';

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
                    ->url(fn ($record) => $record->shipment_id
                        ? ShipmentResource::getUrl('view', ['record' => $record->shipment_id])
                        : null),
                Tables\Columns\TextColumn::make('shipment.first_name')
                    ->label('Name')
                    ->formatStateUsing(fn ($record) => trim(($record->shipment?->first_name ?? '').' '.($record->shipment?->last_name ?? ''))),
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
            ])
            ->recordActions([
                Action::make('markPicked')
                    ->label('Mark Picked')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => $record->picked_at === null)
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
                    }),
            ])
            ->defaultSort('tote_code', 'asc');
    }
}
