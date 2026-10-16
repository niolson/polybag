<?php

namespace App\Filament\Resources\CarrierAccounts;

use App\Filament\Resources\CarrierAccounts\Pages\CreateCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\ListCarrierAccounts;
use App\Filament\Resources\CarrierAccounts\Schemas\CarrierAccountForm;
use App\Filament\Resources\CarrierAccounts\Tables\CarrierAccountsTable;
use App\Models\CarrierAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CarrierAccountResource extends Resource
{
    protected static ?string $model = CarrierAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static \UnitEnum|string|null $navigationGroup = 'Carriers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CarrierAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarrierAccountsTable::configure($table);
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
            'index' => ListCarrierAccounts::route('/'),
            'create' => CreateCarrierAccount::route('/create'),
            'edit' => EditCarrierAccount::route('/{record}/edit'),
        ];
    }
}
