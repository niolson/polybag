<?php

namespace App\Filament\Resources\ShipmentResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('tracking_number')
                    ->maxLength(255),
                Forms\Components\TextInput::make('shipping_method')
                    ->maxLength(255),
                Forms\Components\TextInput::make('weight')
                    ->numeric(),
                Forms\Components\TextInput::make('height')
                    ->numeric(),
                Forms\Components\TextInput::make('width')
                    ->numeric(),
                Forms\Components\TextInput::make('length')
                    ->numeric(),
                Forms\Components\TextInput::make('cost')
                    ->numeric()
                    ->prefix('$'),
                Forms\Components\Toggle::make('shipped')
                    ->default(false),
                Forms\Components\Toggle::make('exported')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tracking_number')
            ->columns([
                Tables\Columns\TextColumn::make('tracking_number'),
                Tables\Columns\TextColumn::make('shipping_method'),
                Tables\Columns\TextColumn::make('weight'),
                Tables\Columns\TextColumn::make('cost')
                    ->money('USD'),
                Tables\Columns\IconColumn::make('shipped')
                    ->boolean(),
                Tables\Columns\IconColumn::make('exported')
                    ->boolean(),
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
