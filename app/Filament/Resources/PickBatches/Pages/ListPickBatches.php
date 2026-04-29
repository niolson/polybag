<?php

namespace App\Filament\Resources\PickBatches\Pages;

use App\Filament\Resources\PickBatches\PickBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListPickBatches extends ListRecords
{
    protected static string $resource = PickBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
