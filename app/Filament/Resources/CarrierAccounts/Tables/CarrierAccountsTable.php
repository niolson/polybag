<?php

namespace App\Filament\Resources\CarrierAccounts\Tables;

use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Location;
use App\Services\SettingsService;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CarrierAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->formatStateUsing(fn (string $state): string => Carrier::labelForName($state))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Account Name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (CarrierAccount $record): string => $record->connectionStatus())
                    ->color(fn (string $state): string => match ($state) {
                        'Connected' => 'success',
                        'Needs Setup' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('scopes_count')
                    ->label('Assigned To')
                    ->counts('scopes')
                    ->suffix(fn (int $state): string => $state === 1 ? ' scope' : ' scopes')
                    ->sortable()
                    ->visible(fn (): bool => app(SettingsService::class)->get('multi_location_enabled', false)
                        || app(SettingsService::class)->get('multi_client_enabled', false)),
                IconColumn::make('active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('carrier')
                    ->relationship('carrier', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Carrier $record): string => $record->label()),
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('carrier.name');
    }
}
