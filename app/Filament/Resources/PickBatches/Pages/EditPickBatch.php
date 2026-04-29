<?php

namespace App\Filament\Resources\PickBatches\Pages;

use App\Filament\Resources\PickBatches\PickBatchResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPickBatch extends EditRecord
{
    protected static string $resource = PickBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
