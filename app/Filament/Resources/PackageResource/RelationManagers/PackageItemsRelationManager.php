<?php

namespace App\Filament\Resources\PackageResource\RelationManagers;

use App\Services\SettingsService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PackageItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'packageItems';

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
                Tables\Columns\TextColumn::make('transparency_codes')
                    ->label('Transparency Codes')
                    ->badge()
                    ->visible(fn (): bool => (bool) SettingsService::get('transparency_enabled', true)),
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
