<?php

namespace App\Filament\Resources\CarrierServiceResource\Pages;

use App\Filament\Resources\CarrierServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCarrierService extends EditRecord
{
    protected static string $resource = CarrierServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
