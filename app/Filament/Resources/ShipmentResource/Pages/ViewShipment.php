<?php

namespace App\Filament\Resources\ShipmentResource\Pages;

use App\Filament\Resources\ShipmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pack')
                ->label('Pack')
                ->icon('heroicon-o-archive-box')
                ->color('primary')
                ->url(fn () => '/pack/'.$this->record->shipment_reference),
            Actions\EditAction::make(),
        ];
    }
}
