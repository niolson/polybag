<?php

namespace App\Filament\Resources\PickBatches\Pages;

use App\Filament\Pages\GeneratePickBatch;
use App\Filament\Resources\PickBatches\PickBatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPickBatches extends ListRecords
{
    protected static string $resource = PickBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate Pick Batch')
                ->icon('heroicon-o-plus')
                ->url(GeneratePickBatch::getUrl()),
        ];
    }
}
