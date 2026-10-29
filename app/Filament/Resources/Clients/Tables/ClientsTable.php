<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Models\Client;
use App\Models\Location;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('return_address_summary')
                    ->label('Return Address')
                    ->state(fn (Client $record): string => $record->hasReturnAddress()
                        ? collect([$record->return_city, $record->return_state_or_province])->filter()->implode(', ')
                        : '—')
                    ->color(fn (Client $record): string => $record->hasReturnAddress() ? 'gray' : 'gray')
                    ->toggleable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime('M j, Y', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
