<?php

namespace App\Filament\Resources\ShippingMethodResource\RelationManagers;

use App\Filament\Support\CarrierLogoColumn;
use App\Models\CarrierService;
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
                CarrierLogoColumn::make('carrier.name', fn ($record) => $record->carrier),
                Tables\Columns\TextColumn::make('service_code'),
                Tables\Columns\TextColumn::make('name'),
                // Nothing buys a service unless it and its carrier are both
                // active; the Ship page shows its offers greyed out.
                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean()
                    ->state(fn (CarrierService $record): bool => $record->active && $record->carrier?->active)
                    ->tooltip(fn (CarrierService $record): ?string => match (true) {
                        ! $record->active => 'Inactive: nothing buys this service. Its offers show greyed out on the Ship page.',
                        ! $record->carrier?->active => "{$record->carrier?->label()} is inactive: nothing buys this service. Its offers show greyed out on the Ship page.",
                        default => null,
                    }),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->preloadRecordSelect(),
            ])
            ->recordActions([
                Actions\DetachAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DetachBulkAction::make(),
            ]);
    }
}
