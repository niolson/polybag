<?php

namespace App\Filament\Resources;

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Filament\Resources\BoxSizeResource\Pages;
use App\Models\BoxSize;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BoxSizeResource extends Resource
{
    protected static ?string $model = BoxSize::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static \UnitEnum|string|null $navigationGroup = 'Shipping Config';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Components\Section::make('Identification')
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('type')
                            ->options(BoxSizeType::class)
                            ->required(),
                    ])->columns(3),
                Components\Section::make('Dimensions')
                    ->schema([
                        Forms\Components\TextInput::make('height')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(999)
                            ->suffix('in'),
                        Forms\Components\TextInput::make('width')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(999)
                            ->suffix('in'),
                        Forms\Components\TextInput::make('length')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(999)
                            ->suffix('in'),
                        Forms\Components\TextInput::make('max_weight')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(150)
                            ->suffix('lbs'),
                        Forms\Components\TextInput::make('empty_weight')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(50)
                            ->suffix('lbs'),
                    ])->columns(5),
                Components\Section::make('Carrier Settings')
                    ->schema([
                        Forms\Components\Select::make('carrier_packaging')
                            ->label('Carrier packaging')
                            ->options(CarrierPackaging::groupedOptions())
                            ->nullable()
                            ->placeholder('Own packaging')
                            ->helperText('A carrier packaging is only useful for a carrier whose account or reseller can rate it: declaring a FedEx Pak on a UPS-only install simply yields no matching rates.'),
                    ]),
                Components\Section::make('Billing')
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false))
                    ->schema([
                        Forms\Components\TextInput::make('materials_cost')
                            ->label('Materials Cost')
                            ->numeric()
                            ->prefix('$')
                            ->step(0.01)
                            ->minValue(0)
                            ->rules(['min:0'])
                            ->placeholder('0.00')
                            ->helperText('Packaging material cost charged per shipment using this box.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->searchable(),
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->formatStateUsing(fn ($state) => $state?->getLabel()),
                Tables\Columns\TextColumn::make('height')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('width')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('length')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_weight')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('empty_weight')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('materials_cost')
                    ->label('Materials Cost')
                    ->money('USD')
                    ->sortable()
                    ->placeholder('—')
                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(BoxSizeType::class),
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBoxSizes::route('/'),
            'create' => Pages\CreateBoxSize::route('/create'),
            'edit' => Pages\EditBoxSize::route('/{record}/edit'),
        ];
    }
}
