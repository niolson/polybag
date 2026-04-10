<?php

namespace App\Filament\Resources\ShippingMethodResource\RelationManagers;

use App\Enums\SpecialServiceMode;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SpecialServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'specialServices';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('mode')
                    ->options(SpecialServiceMode::class)
                    ->required()
                    ->default(SpecialServiceMode::Available->value)
                    ->helperText('Available: shown as an option. Default: pre-selected. Required: always applied.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'compliance' => 'danger',
                        'delivery' => 'info',
                        'pickup' => 'warning',
                        'notifications' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('scope')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('pivot.mode')
                    ->label('Mode')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'required' => 'danger',
                        'default' => 'warning',
                        'available' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ])
            ->filters([])
            ->headerActions([
                Actions\AttachAction::make()
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('active', true))
                    ->preloadRecordSelect()
                    ->form(fn (Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\Select::make('mode')
                            ->options(SpecialServiceMode::class)
                            ->helperText('Available: shown as an option. Default: pre-selected. Required: always applied.')
                            ->required()
                            ->default(SpecialServiceMode::Available->value),
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DetachAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
