<?php

namespace App\Filament\Resources\PickBatches\Tables;

use App\Enums\PickBatchStatus;
use App\Models\Location;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class PickBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user']))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Created By'),
                Tables\Columns\TextColumn::make('total_shipments')
                    ->label('Orders')
                    ->numeric(),
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
                    ->options(PickBatchStatus::class),
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->preload(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('Created From'),
                        Forms\Components\DatePicker::make('created_until')->label('Created Until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['created_from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['created_until'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
                    ),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
