<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Resources\DataSources\DataSourceResource;
use App\Jobs\RunDataSourceImportJob;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\AmazonMarketplaceDiscoveryService;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyFulfillmentOrderActivationService;
use App\Services\ShopifyLocationSynchronizer;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Throwable;

class EditDataSource extends EditRecord
{
    protected static string $resource = DataSourceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($notification = session('oauth_notification')) {
            $message = Notification::make()
                ->{$notification['status']}()
                ->title($notification['title']);

            if (filled($notification['body'] ?? null)) {
                $message->body($notification['body']);
            }

            $message->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_import')
                ->label('Run Import Now')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->dataSource()->import_enabled)
                ->disabled(fn (): bool => ! $this->record->active || $this->amazonMarketplaceIsMissing())
                ->tooltip(fn (): ?string => match (true) {
                    ! $this->record->active => 'This connection is inactive. Activate it to run imports.',
                    $this->amazonMarketplaceIsMissing() => 'Choose an Amazon marketplace before running imports.',
                    default => null,
                })
                ->requiresConfirmation()
                ->modalHeading('Run import now?')
                ->modalDescription('Fetch new shipments from this connection in the background. You will receive a notification when it finishes.')
                ->action(function (): void {
                    RunDataSourceImportJob::dispatch($this->record->id, auth()->id());

                    Notification::make()
                        ->info()
                        ->title('Import queued')
                        ->body("You'll be notified when \"{$this->record->name}\" finishes importing.")
                        ->send();
                }),

            Action::make('import_historical_amazon_orders')
                ->label('Import Historical Orders')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(fn (): bool => config('app.env') === 'local'
                    && $this->dataSource()->source_type === AmazonSource::class
                    && $this->dataSource()->import_enabled)
                ->disabled(fn (): bool => ! $this->dataSource()->active || $this->amazonMarketplaceIsMissing() || (bool) app(SettingsService::class)->get('sandbox_mode', false))
                ->tooltip(fn (): ?string => app(SettingsService::class)->get('sandbox_mode', false) ? 'Disable sandbox mode in App Settings to import real Amazon orders.' : null)
                ->modalHeading('Import historical shipped Amazon orders')
                ->modalDescription('Imports up to 1,000 shipped orders as historical shipped shipments. Large imports use multiple Amazon API pages and may take several minutes. This does not confirm anything back to Amazon or affect scheduled import settings.')
                ->schema([
                    DatePicker::make('created_after')
                        ->label('Orders created on or after')
                        ->default(now()->subMonths(9)->toDateString())
                        ->required(),
                    DatePicker::make('created_before')
                        ->label('Orders created on or before')
                        ->default(now()->subMonths(8)->toDateString())
                        ->afterOrEqual('created_after')
                        ->required(),
                    TextInput::make('max_orders')
                        ->label('Maximum orders')
                        ->numeric()
                        ->integer()
                        ->default(5)
                        ->minValue(1)
                        ->maxValue(AmazonSource::HISTORICAL_IMPORT_MAX_ORDERS)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $createdAfter = Carbon::parse($data['created_after'])->startOfDay();
                    $createdBefore = Carbon::parse($data['created_before'])->endOfDay();

                    RunDataSourceImportJob::dispatch(
                        $this->dataSource()->id,
                        auth()->id(),
                        [
                            '_historical_import' => true,
                            '_historical_created_after' => $createdAfter->utc()->toIso8601String(),
                            '_historical_created_before' => $createdBefore->utc()->toIso8601String(),
                            '_historical_max_orders' => (int) $data['max_orders'],
                        ],
                    );

                    Notification::make()
                        ->info()
                        ->title('Historical Amazon import queued')
                        ->body('Up to '.(int) $data['max_orders'].' shipped orders will be imported. You will receive a notification when it finishes.')
                        ->send();
                }),

            Action::make('shopify_connect')
                ->label(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'Reconnect Shopify' : 'Connect with OAuth')
                ->icon('heroicon-o-link')
                ->color(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'warning' : 'primary')
                ->visible(fn (): bool => $this->record->source_type === ShopifySource::class)
                ->disabled(fn (): bool => ! app(OAuthService::class)->isBrokerConfigured())
                ->tooltip(fn () => app(OAuthService::class)->brokerlessGuidance('Enter your own Shopify app Client ID and Client Secret on this source instead.'))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'Reconnect Shopify' : 'Connect Shopify')
                ->modalDescription(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->record)
                    ? 'This will replace the existing OAuth token. You will be redirected to Shopify to re-authorize.'
                    : 'You will be redirected to Shopify to authorize access. Make sure Shop Domain is saved first.')
                ->action(function (): void {
                    $url = app(OAuthService::class)->initiateAuthorizationForDataSource('shopify', $this->record);
                    $this->redirect($url, navigate: false);
                }),

            Action::make('shopify_disconnect')
                ->label('Disconnect Shopify')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (): bool => $this->record->source_type === ShopifySource::class && app(OAuthService::class)->isDataSourceConnected($this->record))
                ->requiresConfirmation()
                ->modalHeading('Disconnect Shopify OAuth')
                ->modalDescription('This will remove the OAuth access token. The connection will fall back to the custom access token or client credentials flow.')
                ->action(function (): void {
                    app(OAuthService::class)->disconnectDataSource('shopify', $this->record);
                    Notification::make()->success()->title('Shopify disconnected.')->send();
                    $this->redirect(static::getUrl(['record' => $this->record->id]));
                }),

            Action::make('amazon_connect')
                ->label(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->dataSource()) ? 'Reconnect Amazon' : 'Connect Amazon')
                ->icon('heroicon-o-link')
                ->color(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->dataSource()) ? 'warning' : 'primary')
                ->visible(fn (): bool => $this->dataSource()->source_type === AmazonSource::class)
                ->disabled(fn (): bool => ! app(OAuthService::class)->isBrokerConfigured())
                ->tooltip(fn () => app(OAuthService::class)->brokerlessGuidance('Enter your own Amazon Refresh Token and App Client ID/Secret on this source instead.'))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->dataSource()) ? 'Reconnect Amazon' : 'Connect Amazon')
                ->modalDescription('You will be redirected to Seller Central to authorize this Amazon SP-API connection.')
                ->action(function (): void {
                    $url = app(OAuthService::class)->initiateAuthorizationForDataSource('sp-api', $this->dataSource());
                    $this->redirect($url, navigate: false);
                }),

            Action::make('amazon_disconnect')
                ->label('Disconnect Amazon')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (): bool => $this->dataSource()->source_type === AmazonSource::class && app(OAuthService::class)->isDataSourceConnected($this->dataSource()))
                ->requiresConfirmation()
                ->modalHeading('Disconnect Amazon OAuth')
                ->modalDescription('This removes the seller authorization and refresh token from this connection. You can reconnect at any time.')
                ->action(function (): void {
                    $dataSource = $this->dataSource();

                    app(OAuthService::class)->disconnectDataSource('sp-api', $dataSource);
                    Notification::make()->success()->title('Amazon disconnected.')->send();
                    $this->redirect(static::getUrl(['record' => $dataSource->id]));
                }),

            Action::make('amazon_refresh_marketplaces')
                ->label('Refresh Amazon Marketplaces')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->dataSource()->source_type === AmazonSource::class
                    && app(OAuthService::class)->isDataSourceConnected($this->dataSource()))
                ->disabled(fn (): bool => (bool) app(SettingsService::class)->get('sandbox_mode', false))
                ->tooltip(fn (): ?string => app(SettingsService::class)->get('sandbox_mode', false)
                    ? 'Disable sandbox mode in App Settings before refreshing Amazon marketplaces.'
                    : null)
                ->action(function (): void {
                    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($this->dataSource());

                    if (! $result->succeeded) {
                        Notification::make()
                            ->warning()
                            ->title('Amazon marketplace refresh failed')
                            ->body($result->error)
                            ->send();
                    } elseif ($result->selectedMarketplaceUnavailable) {
                        Notification::make()
                            ->warning()
                            ->title('Selected Amazon marketplace was not returned')
                            ->body('The existing marketplace selection was preserved. Review it before changing imports.')
                            ->send();
                    } elseif ($result->marketplaces === []) {
                        Notification::make()
                            ->warning()
                            ->title('No supported Amazon marketplaces found')
                            ->send();
                    } elseif ($result->selectionRequired) {
                        Notification::make()
                            ->warning()
                            ->title('Amazon marketplaces refreshed')
                            ->body('Choose a marketplace before importing.')
                            ->send();
                    } else {
                        Notification::make()
                            ->success()
                            ->title('Amazon marketplaces refreshed')
                            ->send();
                    }

                    $this->refreshFormData(['settings.marketplace_id']);
                }),

            Action::make('sync_shopify_locations')
                ->label('Sync Shopify Locations')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->dataSource()->source_type === ShopifySource::class)
                ->action(function (): void {
                    try {
                        $dataSource = $this->dataSource();
                        $result = app(ShopifyLocationSynchronizer::class)->synchronize($dataSource);

                        Notification::make()
                            ->success()
                            ->title('Shopify locations synchronized')
                            ->body("Found {$result['synced']} active locations; {$result['deactivated']} previously known locations marked inactive; {$result['auto_mapped']} auto-mapped.")
                            ->send();

                        $this->redirect(static::getUrl(['record' => $dataSource->id]));
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Shopify location sync failed')
                            ->body($exception->getMessage())
                            ->send();
                    }
                }),

            Action::make('activate_fulfillment_order_import')
                ->label('Activate Fulfillment-Order Import')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->dataSource()->source_type === ShopifySource::class
                    && $this->dataSource()->import_enabled
                    && ! ($this->dataSource()->settings['fulfillment_order_import_enabled'] ?? false))
                ->requiresConfirmation()
                ->modalHeading('Activate fulfillment-order imports?')
                ->modalDescription('This one-way change imports one Shipment per Shopify fulfillment order. It cannot be switched back because doing so could create duplicate work.')
                ->action(function (): void {
                    try {
                        $dataSource = $this->dataSource();
                        app(ShopifyFulfillmentOrderActivationService::class)->activate($dataSource);

                        Notification::make()
                            ->success()
                            ->title('Fulfillment-order imports activated')
                            ->send();

                        $this->redirect(static::getUrl(['record' => $dataSource->id]));
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Activation requirements not met')
                            ->body($exception->getMessage())
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Shopify scope validation failed')
                            ->body($exception->getMessage())
                            ->send();
                    }
                }),

            DeleteAction::make(),
        ];
    }

    /**
     * Split secret keys into the encrypted column; preserve existing values for
     * fields left blank (password fields that weren't re-entered).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validateMfaRequiredForAmazon($data);

        $record = $this->getRecord();
        $existing = $record->settings ?? [];
        $existingSecrets = $record->secret_settings ?? [];
        $submitted = $data['settings'] ?? [];

        // Route newly-submitted secret keys to secret_settings; preserve existing for blanks.
        foreach (DataSource::SECRET_SETTINGS_KEYS as $key) {
            if (array_key_exists($key, $submitted) && filled($submitted[$key])) {
                $existingSecrets[$key] = $submitted[$key];
            }
            unset($submitted[$key]);
        }

        // Preserve non-secret existing settings for keys not in submission.
        foreach ($existing as $key => $value) {
            if (! array_key_exists($key, $submitted)) {
                $submitted[$key] = $value;
            }
        }

        $data['settings'] = $submitted;
        $data['secret_settings'] = $existingSecrets ?: null;

        return $data;
    }

    protected function afterSave(): void
    {
        $dataSource = $this->dataSource();

        if ($dataSource->source_type !== ShopifySource::class) {
            return;
        }

        $preservedConflicts = Shipment::query()
            ->join('data_source_locations', 'data_source_locations.id', '=', 'shipments.data_source_location_id')
            ->where('shipments.data_source_id', $dataSource->id)
            ->whereNotNull('data_source_locations.location_id')
            ->where(function ($query): void {
                $query->whereNull('shipments.location_id')
                    ->orWhereColumn('shipments.location_id', '!=', 'data_source_locations.location_id');
            })
            ->whereHas('packages')
            ->count('shipments.id');

        if ($preservedConflicts > 0) {
            Notification::make()
                ->warning()
                ->title('Packed shipments preserved')
                ->body("{$preservedConflicts} Shipments already have a Package and were not moved to the new mapping. Resolve open location conflicts manually; shipped history remains unchanged.")
                ->send();
        }
    }

    private function dataSource(): DataSource
    {
        $record = $this->getRecord();

        if (! $record instanceof DataSource) {
            throw new \LogicException('The Data Source record is unavailable.');
        }

        return $record;
    }

    private function amazonMarketplaceIsMissing(): bool
    {
        $dataSource = $this->dataSource();

        return $dataSource->source_type === AmazonSource::class
            && blank($dataSource->settings['marketplace_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateMfaRequiredForAmazon(array $data): void
    {
        if (($data['source_type'] ?? null) !== AmazonSource::class || ! ($data['active'] ?? false)) {
            return;
        }

        if (app(SettingsService::class)->get('require_mfa', false)) {
            return;
        }

        $message = 'Amazon SP-API connections give access to customer PII, so Multi-Factor Authentication must be required for all users before this connection can be active. Enable it in App Settings → Authentication first.';

        Notification::make()
            ->title('Multi-Factor Authentication required')
            ->body($message)
            ->danger()
            ->send();

        $this->addError('data.active', $message);

        throw new Halt;
    }
}
