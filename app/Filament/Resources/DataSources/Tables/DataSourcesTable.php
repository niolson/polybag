<?php

namespace App\Filament\Resources\DataSources\Tables;

use App\Enums\ScheduleInterval;
use App\Jobs\RunDataSourceImportJob;
use App\Models\DataSource;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DataSourcesTable
{
    private const DRIVER_LABELS = [
        DatabaseSource::class => 'Database',
        ShopifySource::class => 'Shopify',
        AmazonSource::class => 'Amazon SP-API',
    ];

    public static function configure(Table $table): Table
    {
        /** @var SettingsService $settings */
        $settings = app(SettingsService::class);
        $multiClient = (bool) $settings->get('multi_client_enabled', false);

        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Client')
                    ->placeholder('—')
                    ->sortable()
                    ->visible($multiClient),

                TextColumn::make('source_type')
                    ->label('Driver')
                    ->formatStateUsing(fn (string $state): string => self::DRIVER_LABELS[$state] ?? class_basename($state))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('schedule_interval')
                    ->label('Schedule')
                    ->formatStateUsing(fn (?ScheduleInterval $state): string => $state?->getLabel() ?? '—')
                    ->badge()
                    ->color('info'),

                IconColumn::make('global_export')
                    ->label('Global Export')
                    ->boolean()
                    ->sortable()
                    ->visible($multiClient),

                IconColumn::make('active')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('import_enabled')
                    ->label('Imports')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('run_import')
                    ->label('Run Import')
                    ->icon(Heroicon::ArrowDownTray)
                    ->visible(fn (DataSource $record): bool => $record->importsOrders())
                    ->requiresConfirmation()
                    ->modalHeading('Run import now?')
                    ->modalDescription('Fetch new shipments from this connection in the background. You will receive a notification when it finishes.')
                    ->action(function (DataSource $record): void {
                        RunDataSourceImportJob::dispatch($record->id, auth()->id());

                        Notification::make()
                            ->info()
                            ->title('Import queued')
                            ->body("You'll be notified when \"{$record->name}\" finishes importing.")
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
