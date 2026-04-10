<?php

namespace App\Filament\Resources\SpecialServices;

use App\Filament\Resources\SpecialServices\Pages\ListSpecialServices;
use App\Models\SpecialService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SpecialServiceResource extends Resource
{
    protected static ?string $model = SpecialService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Special Services';

    /** Catalog is code-owned — no creating or deleting via UI. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        // Not used — no create/edit pages. Active is toggled inline on the table.
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily('mono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'compliance' => 'danger',
                        'delivery' => 'info',
                        'pickup' => 'warning',
                        'notifications' => 'success',
                        'returns' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('scope')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\IconColumn::make('requires_value')
                    ->boolean()
                    ->label('Needs Value'),
                Tables\Columns\ToggleColumn::make('active'),
            ])
            ->defaultSort('category')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn () => SpecialService::distinct()->pluck('category', 'category')->sort()),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->recordActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpecialServices::route('/'),
        ];
    }
}
