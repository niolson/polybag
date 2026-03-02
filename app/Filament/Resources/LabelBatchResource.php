<?php

namespace App\Filament\Resources;

use App\Enums\LabelBatchStatus;
use App\Enums\Role;
use App\Filament\Resources\LabelBatchResource\Pages;
use App\Filament\Resources\LabelBatchResource\RelationManagers\LabelBatchItemsRelationManager;
use App\Models\LabelBatch;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class LabelBatchResource extends Resource
{
    protected static ?string $model = LabelBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Batch Shipments';

    protected static ?string $modelLabel = 'Batch Shipment';

    protected static ?string $pluralModelLabel = 'Batch Shipments';

    protected static ?string $slug = 'batch-shipments';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Admin) ?? false;
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
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
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

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->columns(2)
            ->schema([
                Components\Section::make('Batch Details')
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('id')
                            ->label('Batch ID'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('user.name')
                            ->label('Started By'),
                        TextEntry::make('boxSize.label')
                            ->label('Box Size'),
                        TextEntry::make('label_format')
                            ->label('Label Format')
                            ->formatStateUsing(fn ($state) => strtoupper($state)),
                        TextEntry::make('label_dpi')
                            ->label('Label DPI')
                            ->placeholder('Default'),
                    ]),

                Components\Section::make('Progress')
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('total_shipments')
                            ->label('Total'),
                        TextEntry::make('successful_shipments')
                            ->label('Successful')
                            ->color('success'),
                        TextEntry::make('failed_shipments')
                            ->label('Failed')
                            ->color('danger'),
                        TextEntry::make('total_cost')
                            ->label('Total Cost')
                            ->money('USD'),
                        TextEntry::make('started_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->dateTime(),
                    ]),
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
