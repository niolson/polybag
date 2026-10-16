<?php

namespace App\Filament\Resources\PickBatches;

use App\Enums\Role;
use App\Filament\Pages\GeneratePickBatch;
use App\Filament\Resources\PickBatches\Pages\ListPickBatches;
use App\Filament\Resources\PickBatches\Pages\ViewPickBatch;
use App\Filament\Resources\PickBatches\RelationManagers\PickBatchShipmentsRelationManager;
use App\Filament\Resources\PickBatches\Tables\PickBatchesTable;
use App\Models\PickBatch;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

class PickBatchResource extends Resource
{
    protected static ?string $model = PickBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Pick Batches';

    protected static ?string $modelLabel = 'Pick Batch';

    protected static ?string $pluralModelLabel = 'Pick Batches';

    protected static UnitEnum|string|null $navigationGroup = 'Picking';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return (auth()->user()?->role->isAtLeast(Role::Manager) ?? false)
            && app(SettingsService::class)->get('picking_enabled', false);
    }

    /**
     * Generate Pick Batch has no sidebar item of its own, so this one stays lit there.
     *
     * @return array<string>
     */
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [static::getRouteBaseName().'.*', GeneratePickBatch::getRouteName()];
    }

    public static function table(Table $table): Table
    {
        return PickBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PickBatchShipmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPickBatches::route('/'),
            'view' => ViewPickBatch::route('/{record}'),
        ];
    }
}
