<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarrierServiceResource\Pages;
use App\Models\CarrierService;
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

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

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
                Forms\Components\CheckboxList::make('boxSizes')
                    ->relationship(titleAttribute: 'label')
                    ->bulkToggleable()
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('carrier.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('service_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\IconColumn::make('active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('carrier.active')
                    ->label('Carrier Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\EditAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListCarrierServices::route('/'),
            'create' => Pages\CreateCarrierService::route('/create'),
            'edit' => Pages\EditCarrierService::route('/{record}/edit'),
        ];
    }
}
