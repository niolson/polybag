<?php

namespace App\Filament\Resources\BoxSizeResource\Pages;

use App\Filament\Pages\PrintBoxSizeBarcodes;
use App\Filament\Resources\BoxSizeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBoxSizes extends ListRecords
{
    protected static string $resource = BoxSizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('printBarcodes')
                ->label('Print Barcodes')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(PrintBoxSizeBarcodes::getUrl())
                ->openUrlInNewTab(),
            Actions\CreateAction::make(),
        ];
    }
}
