<?php

namespace App\Filament\Resources\BoxSizeResource\Pages;

use App\Filament\Pages\PrintBoxSizeBarcodes;
use App\Filament\Resources\BoxSizeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Opens on the boxes in use: the inactive carrier packaging the reference-data
     * sync seeds would otherwise fill the first pages.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('active', true)),
            'inactive' => Tab::make('Inactive')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('active', false)),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'active';
    }
}
