<?php

namespace App\Filament\Resources\ShippingMethodResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CarrierServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'carrierServices';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('carrier.name'),
                Tables\Columns\TextColumn::make('service_code'),
                Tables\Columns\TextColumn::make('name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->with('carrier'))
                    ->recordTitle(fn ($record) => "{$record->carrier->name} — {$record->name}")
                    ->modalWidth('md'),
            ])
            ->recordActions([
                Actions\DetachAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DetachBulkAction::make(),
            ]);
    }
}
