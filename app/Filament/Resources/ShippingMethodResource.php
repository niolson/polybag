<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShippingMethodResource\Pages;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\AliasesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\CarrierServicesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\ShippingRulesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\SpecialServicesRelationManager;
use App\Models\Location;
use App\Models\ShippingMethod;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('commitment_days')
                    ->numeric(),
                Forms\Components\Toggle::make('active')
                    ->default(true),
                Forms\Components\Toggle::make('is_expedited')
                    ->label('Expedited')
                    ->helperText('When enabled, shipments with this method are prioritized in auto-generated pick batches.')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('commitment_days')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_expedited')
                    ->label('Expedited')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CarrierServicesRelationManager::class,
            SpecialServicesRelationManager::class,
            AliasesRelationManager::class,
            ShippingRulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShippingMethods::route('/'),
            'create' => Pages\CreateShippingMethod::route('/create'),
            'edit' => Pages\EditShippingMethod::route('/{record}/edit'),
        ];
    }
}
