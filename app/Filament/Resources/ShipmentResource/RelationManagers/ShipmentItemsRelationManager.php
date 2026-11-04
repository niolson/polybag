<?php

namespace App\Filament\Resources\ShipmentResource\RelationManagers;

use App\Filament\Resources\ProductResource;
use App\Models\DataSource;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\SettingsService;
use App\Services\ShipmentImport\ShipmentItemImporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ShipmentItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipmentItems';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('quantity')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('value')
                    ->numeric()
                    ->suffix(fn (): string => $this->shipmentCurrency()),
                Forms\Components\Toggle::make('transparency')
                    ->visible(fn (): bool => (bool) app(SettingsService::class)->get('transparency_enabled', true)),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->description(fn (): ?string => ($source = $this->sourceOwningItems()) instanceof DataSource
                ? "These items come from {$source->name}. Change them there, and the next import brings the change here."
                : null)
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->url(fn ($record): ?string => $record->product instanceof Product && ProductResource::canEdit($record->product)
                        ? ProductResource::getUrl('edit', ['record' => $record->product])
                        : null),
                Tables\Columns\TextColumn::make('product.barcode')
                    ->label('Barcode'),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('value')
                    ->money(fn (): string => $this->shipmentCurrency()),
                Tables\Columns\IconColumn::make('transparency')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->visible(fn (): bool => (bool) app(SettingsService::class)->get('transparency_enabled', true)),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    /**
     * An imported Shipment's items belong to its source: the next import
     * rewrites them, and a change made here in between would leave a printed
     * pack slip out of date (`manual-ship-contents/02`). Read-only also denies
     * the create, edit and delete actions, not only hides them.
     */
    public function isReadOnly(): bool
    {
        return $this->sourceOwningItems() instanceof DataSource || parent::isReadOnly();
    }

    /**
     * The Shipment's source, when it imports items.
     */
    private function sourceOwningItems(): ?DataSource
    {
        $shipment = $this->getOwnerRecord();
        $source = $shipment instanceof Shipment ? $shipment->dataSource : null;

        return $source !== null && app(ShipmentItemImporter::class)->isEnabledFor($source) ? $source : null;
    }

    /**
     * The currency every item's value is in: the Shipment's (`order-currency/01`).
     */
    private function shipmentCurrency(): string
    {
        $shipment = $this->getOwnerRecord();

        return $shipment instanceof Shipment ? $shipment->currency : Shipment::DEFAULT_CURRENCY;
    }
}
