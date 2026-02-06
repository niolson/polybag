<?php

namespace App\Filament\Resources\Carriers;

use App\Filament\Resources\Carriers\Pages\CreateCarrier;
use App\Filament\Resources\Carriers\Pages\EditCarrier;
use App\Filament\Resources\Carriers\Pages\ListCarriers;
use App\Filament\Resources\Carriers\Schemas\CarrierForm;
use App\Filament\Resources\Carriers\Tables\CarriersTable;
use App\Models\Carrier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CarrierResource extends Resource
{
    protected static ?string $model = Carrier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CarrierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarriersTable::configure($table);
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
            'index' => ListCarriers::route('/'),
            'create' => CreateCarrier::route('/create'),
            'edit' => EditCarrier::route('/{record}/edit'),
        ];
    }
}
