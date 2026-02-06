<?php

namespace App\Filament\Resources\CarrierServiceResource\Pages;

use App\Filament\Resources\CarrierServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCarrierServices extends ListRecords
{
    protected static string $resource = CarrierServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
