<?php

namespace App\Filament\Resources;

use App\Filament\Support\AddressForm;
use App\Filament\Resources\LocationResource\Pages;
use App\Models\Carrier;
use App\Models\Location;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Components\Section::make('Location Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_default')
                            ->label('Default Location')
                            ->helperText('Only one location can be the default. Setting this will unset the current default.'),
                        Forms\Components\Toggle::make('active')
                            ->default(true),
                        Forms\Components\Select::make('timezone')
                            ->options(fn () => collect(timezone_identifiers_list())
                                ->filter(fn ($tz) => str_starts_with($tz, 'America/') || str_starts_with($tz, 'Pacific/') || str_starts_with($tz, 'US/'))
                                ->mapWithKeys(fn ($tz) => [$tz => str_replace('_', ' ', $tz)]))
                            ->searchable()
                            ->default('America/New_York')
                            ->required(),
                    ]),
                Components\Section::make('Address')
                    ->schema([
                        Forms\Components\TextInput::make('company')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('first_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('last_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address1')
                            ->label('Street Address')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address2')
                            ->label('Street Address 2')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('city')
                            ->required()
                            ->maxLength(255),
                        AddressForm::administrativeAreaSelect(),
                        Forms\Components\TextInput::make('postal_code')
                            ->required()
                            ->maxLength(20),
                        AddressForm::countrySelect(),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(20),
                    ])->columns(2),
                Components\Section::make('Carrier Pickup Schedule')
                    ->schema([
                        Forms\Components\Repeater::make('carrierLocations')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('carrier_id')
                                    ->label('Carrier')
                                    ->options(fn () => Carrier::active()->pluck('name', 'id'))
                                    ->required()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                Forms\Components\CheckboxList::make('pickup_days')
                                    ->label('Pickup Days')
                                    ->options([
                                        0 => 'Sunday',
                                        1 => 'Monday',
                                        2 => 'Tuesday',
                                        3 => 'Wednesday',
                                        4 => 'Thursday',
                                        5 => 'Friday',
                                        6 => 'Saturday',
                                    ])
                                    ->default([1, 2, 3, 4, 5])
                                    ->columns(7),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Add Carrier')
                            ->columnSpanFull(),
                    ])
                    ->description('Configure which days each carrier picks up from this location. Defaults to weekdays if not set.')
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city'),
                Tables\Columns\TextColumn::make('state_or_province')
                    ->label('State'),
                Tables\Columns\TextColumn::make('country'),
                Tables\Columns\TextColumn::make('timezone')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
