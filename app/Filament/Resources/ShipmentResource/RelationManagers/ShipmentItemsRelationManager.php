<?php

namespace App\Filament\Resources\ShipmentResource\RelationManagers;

use App\Services\SettingsService;
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
                    ->prefix('$'),
                Forms\Components\Toggle::make('transparency')
                    ->visible(fn (): bool => (bool) app(SettingsService::class)->get('transparency_enabled', true)),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product'),
                Tables\Columns\TextColumn::make('product.barcode')
                    ->label('Barcode'),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('value')
                    ->money('USD'),
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
}
