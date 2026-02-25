<?php

namespace App\Filament\Resources;

use App\Enums\ShippingRuleAction;
use App\Filament\Resources\ShippingRuleResource\Pages;
use App\Models\ShippingRule;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ShippingRuleResource extends Resource
{
    protected static ?string $model = ShippingRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('shipping_method_id')
                    ->relationship('shippingMethod', 'name')
                    ->placeholder('All Methods')
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('action')
                    ->options(ShippingRuleAction::class)
                    ->required(),
                Forms\Components\Select::make('carrier_service_id')
                    ->relationship('carrierService', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->carrier->name} — {$record->name}")
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('priority')
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('enabled')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('shippingMethod.name')
                    ->label('Shipping Method')
                    ->placeholder('All Methods'),
                Tables\Columns\TextColumn::make('action')
                    ->badge(),
                Tables\Columns\TextColumn::make('carrierService.name')
                    ->label('Carrier Service')
                    ->formatStateUsing(fn ($state, $record) => "{$record->carrierService->carrier->name} — {$state}"),
                Tables\Columns\TextColumn::make('priority')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('enabled'),
            ])
            ->defaultSort('priority')
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShippingRules::route('/'),
            'create' => Pages\CreateShippingRule::route('/create'),
            'edit' => Pages\EditShippingRule::route('/{record}/edit'),
        ];
    }
}
