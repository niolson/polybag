<?php

namespace App\Filament\Resources\BoxSizeResource\Pages;

use App\Filament\Resources\BoxSizeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBoxSize extends EditRecord
{
    protected static string $resource = BoxSizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
