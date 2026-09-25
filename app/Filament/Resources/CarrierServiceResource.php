<?php

namespace App\Filament\Resources;

use App\Enums\ContentClass;
use App\Filament\Resources\CarrierServiceResource\Pages;
use App\Filament\Support\CarrierLogoColumn;
use App\Models\CarrierService;
use App\Models\Location;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CarrierServiceResource extends Resource
{
    protected static ?string $model = CarrierService::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static \UnitEnum|string|null $navigationGroup = 'Shipping Config';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\Select::make('carrier_id')
                    ->relationship('carrier', 'name')
                    ->required()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                    ]),
                Forms\Components\TextInput::make('service_code')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Toggle::make('active')
                    ->default(true)
                    ->helperText('Disabled services will not be used for rate shopping.'),
                Forms\Components\Toggle::make('can_ship_to_po_boxes')
                    ->label('Can ship to PO Boxes')
                    ->default(false)
                    ->helperText('Only USPS services and USPS-last-mile hybrids (e.g. FedEx Ground Economy, UPS Ground Saver) can deliver to PO Boxes.'),
                Forms\Components\Toggle::make('can_ship_to_military_addresses')
                    ->label('Can ship to military addresses')
                    ->default(false)
                    ->helperText('APO/FPO/DPO addresses require USPS for the final leg, same as PO Boxes.'),
                // Authored by the carrier catalog seed, which restores it on
                // every start, so it is shown rather than edited.
                Forms\Components\Select::make('required_contents')
                    ->label('Requires contents')
                    ->options(ContentClass::class)
                    ->placeholder('Any contents')
                    ->disabled()
                    ->helperText('Offered only to a package whose every item is a product marked with these contents.')
                    ->visible(fn (?CarrierService $record): bool => $record?->required_contents !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                CarrierLogoColumn::make('carrier.name', fn ($record) => $record->carrier?->name)
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('service_code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('can_ship_to_po_boxes')
                    ->label('PO Boxes')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('can_ship_to_military_addresses')
                    ->label('Military')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('required_contents')
                    ->label('Requires')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('carrier.active')
                    ->label('Carrier Active')
                    ->boolean()
                    ->sortable(),
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
            CarrierServiceResource\RelationManagers\SpecialServicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCarrierServices::route('/'),
            'create' => Pages\CreateCarrierService::route('/create'),
            'edit' => Pages\EditCarrierService::route('/{record}/edit'),
        ];
    }
}
