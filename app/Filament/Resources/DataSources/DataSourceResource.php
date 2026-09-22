<?php

namespace App\Filament\Resources\DataSources;

use App\Enums\Role;
use App\Filament\Resources\DataSources\Pages\CreateDataSource;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Filament\Resources\DataSources\Pages\ListDataSources;
use App\Filament\Resources\DataSources\Schemas\DataSourceForm;
use App\Filament\Resources\DataSources\Tables\DataSourcesTable;
use App\Models\DataSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DataSourceResource extends Resource
{
    protected static ?string $model = DataSource::class;

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Admin);
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static \UnitEnum|string|null $navigationGroup = 'Integrations';

    /**
     * The UI calls a DataSource a Connection: a connected account that may
     * import orders, sell postage, receive tracking, or several of those. The
     * model, table and classes keep the DataSource name (see CONTEXT.md).
     */
    protected static ?string $navigationLabel = 'Connections';

    protected static ?string $modelLabel = 'connection';

    protected static ?string $pluralModelLabel = 'connections';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DataSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DataSourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataSources::route('/'),
            'create' => CreateDataSource::route('/create'),
            'edit' => EditDataSource::route('/{record}/edit'),
        ];
    }
}
