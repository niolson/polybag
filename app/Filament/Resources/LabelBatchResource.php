<?php

namespace App\Filament\Resources;

use App\Enums\LabelBatchStatus;
use App\Enums\Role;
use App\Filament\Resources\LabelBatchResource\Pages;
use App\Filament\Resources\LabelBatchResource\RelationManagers\LabelBatchItemsRelationManager;
use App\Models\LabelBatch;
use App\Models\Location;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class LabelBatchResource extends Resource
{
    protected static ?string $model = LabelBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Label Batches';

    protected static ?string $modelLabel = 'Label Batch';

    protected static ?string $pluralModelLabel = 'Label Batches';

    protected static ?string $slug = 'batch-shipments';

    protected static \UnitEnum|string|null $navigationGroup = 'Manage';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return (auth()->user()?->role->isAtLeast(Role::Admin) ?? false)
            && app(SettingsService::class)->get('batch_shipping_enabled', true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'boxSize']))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User'),
                Tables\Columns\TextColumn::make('boxSize.label')
                    ->label('Box Size'),
                Tables\Columns\TextColumn::make('total_shipments')
                    ->label('Total')
                    ->numeric(),
                Tables\Columns\TextColumn::make('successful_shipments')
                    ->label('Success')
                    ->numeric()
                    ->color('success'),
                Tables\Columns\TextColumn::make('failed_shipments')
                    ->label('Failed')
                    ->numeric()
                    ->color('danger'),
                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total Cost')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(LabelBatchStatus::class),
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->preload(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('Created From'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Created Until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['created_from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['created_until'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators['created_from'] = 'From '.$data['created_from'];
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators['created_until'] = 'Until '.$data['created_until'];
                        }

                        return $indicators;
                    }),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LabelBatchItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLabelBatches::route('/'),
            'view' => Pages\ViewLabelBatch::route('/{record}'),
        ];
    }
}
