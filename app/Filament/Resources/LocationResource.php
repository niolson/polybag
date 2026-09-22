<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages;
use App\Filament\Support\AddressForm;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Location;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static \UnitEnum|string|null $navigationGroup = 'Admin';

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_location_enabled', false);
    }

    public static function hasActiveFedexCarrier(): bool
    {
        return Carrier::active()
            ->where('name', 'FedEx')
            ->exists();
    }

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
                                ->filter(fn ($tz): bool => str_starts_with($tz, 'America/') || str_starts_with($tz, 'Pacific/') || str_starts_with($tz, 'US/'))
                                ->mapWithKeys(fn ($tz): array => [$tz => str_replace('_', ' ', $tz)]))
                            ->searchable()
                            ->default('America/New_York')
                            ->required(),
                        Forms\Components\Select::make('fedex_hub_id')
                            ->label('FedEx Hub ID')
                            ->helperText('Used for FedEx Ground Economy / SmartPost shipments from this origin.')
                            ->options(config('fedex.ground_economy_hubs'))
                            ->searchable()
                            ->placeholder('Select a FedEx hub')
                            ->visible(fn (): bool => static::hasActiveFedexCarrier())
                            ->dehydrated(fn ($state): bool => static::hasActiveFedexCarrier() || filled($state)),
                    ]),
                Components\Section::make('Address')
                    ->schema(AddressForm::recipientAddressFields(
                        includeCompany: true,
                        includePhone: true,
                        requireNames: true,
                        requirePostalCode: true,
                        postalCodeMaxLength: 20,
                        phoneMaxLength: 20,
                    ))->columns(2),
                Components\Section::make('Carrier Settings')
                    ->schema([
                        Forms\Components\Repeater::make('carrierLocations')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('carrier_id')
                                    ->label('Carrier')
                                    ->options(fn () => Carrier::active()->pluck('name', 'id'))
                                    ->required()
                                    ->live()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                Forms\Components\CheckboxList::make('pickup_days')
                                    ->label('Pickup Days')
                                    ->helperText('Ship dates land only on these days. Removing a carrier from this list does not stop scheduling it — it returns that carrier to the Monday-Friday default.')
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
                                    ->minItems(1)
                                    ->columns(7),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Add Carrier')
                            ->columnSpanFull(),
                    ])
                    ->description('Which days each carrier collects from this location. A carrier you do not list here is picked up Monday-Friday.')
                    ->collapsible(),
                Components\Section::make('Carrier Accounts')
                    ->schema([
                        Forms\Components\Repeater::make('carrierAccountScopes')
                            // Only rows that target a carrier account. A row for an
                            // Amazon connection is edited on the connection, and
                            // leaving it out of this query also keeps the repeater
                            // from deleting it.
                            ->relationship(modifyQueryUsing: fn (Builder $query): Builder => $query->whereNotNull('carrier_account_id'))
                            ->schema([
                                Forms\Components\Select::make('carrier_account_id')
                                    ->label('Account')
                                    ->options(fn () => CarrierAccount::with('carrier')
                                        ->where('active', true)
                                        ->get()
                                        ->mapWithKeys(fn ($a): array => [$a->id => "[{$a->carrier->name}] {$a->name}"]))
                                    ->required(),

                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Assign Account')
                            ->columnSpanFull(),
                    ])
                    ->description('Assign carrier accounts to this location. Client-specific overrides are configured per client.')
                    ->visible(fn (): bool => (bool) app(SettingsService::class)->get('multi_location_enabled', false))
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
