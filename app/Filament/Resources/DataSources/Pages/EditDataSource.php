<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Resources\DataSources\Concerns\SetsUpOffAmazonShipping;
use App\Filament\Resources\DataSources\DataSourceResource;
use App\Http\Integrations\Amazon\AmazonSpApiEnvironment;
use App\Jobs\RunDataSourceImportJob;
use App\Jobs\SyncProductCatalogJob;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\AmazonMarketplaceDiscoveryService;
use App\Services\OAuthService;
use App\Services\PostageSources\OffAmazonShippingCheck;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyLocationSynchronizer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EditDataSource extends EditRecord
{
    use SetsUpOffAmazonShipping;

    protected static string $resource = DataSourceResource::class;

    /**
     * The assignments submitted with the form, applied after the connection
     * itself is saved so they are checked against its saved Client. Null when
     * the repeater was hidden, which leaves existing rows alone.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $pendingOffAmazonShippingScopes = null;

    private bool $offeredOffAmazonShippingBeforeSave = false;

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

        // Offered once, after a new connection is connected; never started on its own.
        if (session('offer_product_catalog_sync') === $this->dataSource()->id
            && $this->dataSource()->productCatalogSyncRun() === null
            && self::canSyncProductCatalog($this->dataSource())) {
            $this->defaultAction = 'sync_product_catalog';
        }
    }

    /**
     * Whether the Sync Product Catalog action is offered: an active connection
     * that can list a catalog, while imports may write Products.
     */
    public static function canSyncProductCatalog(DataSource $dataSource): bool
    {
        return $dataSource->syncsProductCatalog()
            && (bool) config('shipment-import.behavior.auto_update_products', true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_import')
                ->authorize('update')
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

            Action::make('sync_product_catalog')
                ->authorize('update')
                ->label('Sync Product Catalog')
                ->icon('heroicon-o-cube')
                ->color('gray')
                ->visible(fn (): bool => self::canSyncProductCatalog($this->dataSource()))
                ->disabled(fn (): bool => (bool) $this->dataSource()->productCatalogSyncRun()?->isRunning())
                ->tooltip(fn (): ?string => $this->dataSource()->productCatalogSyncRun()?->isRunning()
                    ? 'A catalog sync of this connection is running.'
                    : null)
                ->requiresConfirmation()
                ->modalHeading('Sync product catalog?')
                ->modalDescription('Add every variant of this store\'s active products to Products, in the background. Only empty fields are filled: a name, weight or customs field already on a Product is kept. Nothing is deleted, and nothing is written to the store.')
                ->modalSubmitActionLabel('Sync')
                ->action(function (): void {
                    SyncProductCatalogJob::dispatch($this->dataSource()->id, auth()->id());

                    Notification::make()
                        ->info()
                        ->title('Catalog sync queued')
                        ->body('The last run is shown under Product Catalog on this connection.')
                        ->send();
                }),

            Action::make('shopify_connect')
                ->authorize('update')
                ->label(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'Reconnect Shopify' : 'Connect Shopify')
                ->icon('heroicon-o-link')
                ->color(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'warning' : 'primary')
                ->visible(fn (): bool => $this->record->source_type === ShopifySource::class)
                ->disabled(fn (): bool => ! app(OAuthService::class)->isBrokerConfigured())
                ->tooltip(fn () => app(OAuthService::class)->brokerlessGuidance('Enter your own Shopify app Client ID and Client Secret on this connection instead.'))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'Reconnect Shopify' : 'Connect Shopify')
                ->modalDescription(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->record)
                    ? 'This will replace the existing OAuth token. You will be redirected to Shopify to re-authorize.'
                    : 'You will be redirected to Shopify to authorize access. Make sure Shop Domain is saved first.')
                ->action(function (): void {
                    try {
                        $url = app(OAuthService::class)->initiateAuthorizationForDataSource('shopify', $this->record);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Cannot connect Shopify')->body($e->getMessage())->send();

                        return;
                    }

                    $this->redirect($url, navigate: false);
                }),

            Action::make('shopify_disconnect')
                ->authorize('update')
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
                ->authorize('update')
                ->label(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->dataSource()) ? 'Reconnect Amazon' : 'Connect Amazon')
                ->icon('heroicon-o-link')
                ->color(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->dataSource()) ? 'warning' : 'primary')
                ->visible(fn (): bool => $this->dataSource()->source_type === AmazonSource::class)
                ->disabled(fn (): bool => ! app(OAuthService::class)->isBrokerConfigured())
                ->tooltip(fn () => app(OAuthService::class)->brokerlessGuidance('Enter your own Amazon Refresh Token and App Client ID/Secret on this connection instead.'))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => app(OAuthService::class)->isDataSourceConnected($this->dataSource()) ? 'Reconnect Amazon' : 'Connect Amazon')
                ->modalDescription('You will be redirected to Seller Central to authorize this Amazon SP-API connection.')
                ->action(function (): void {
                    $url = app(OAuthService::class)->initiateAuthorizationForDataSource('sp-api', $this->dataSource());
                    $this->redirect($url, navigate: false);
                }),

            Action::make('amazon_disconnect')
                ->authorize('update')
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
                ->authorize('update')
                ->label('Refresh Amazon Marketplaces')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->dataSource()->source_type === AmazonSource::class
                    && app(OAuthService::class)->isDataSourceConnected($this->dataSource()))
                ->disabled(fn (): bool => app(AmazonSpApiEnvironment::class)->readsFromSandbox())
                ->tooltip(fn (): ?string => app(AmazonSpApiEnvironment::class)->readsFromSandbox()
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
                            ->body('Check that the seller account is registered in a marketplace PolyBag supports, then try connecting again.')
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
                ->authorize('update')
                ->label('Sync Shopify Locations')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->dataSource()->isShopify())
                ->disabled(fn (): bool => ! $this->dataSource()->hasShopifyCredentials())
                ->tooltip(fn (): ?string => $this->dataSource()->hasShopifyCredentials()
                    ? null
                    : 'Connect Shopify before synchronizing its locations.')
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

            Action::make('check_off_amazon_shipping')
                ->authorize('update')
                ->label('Check Again')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->tooltip('Ask Amazon again whether this account can ship orders from other channels.')
                ->visible(fn (): bool => $this->dataSource()->isAmazon() && $this->dataSource()->offers_off_amazon_shipping)
                ->action(function (): void {
                    $this->notifyOffAmazonShippingCheck(app(OffAmazonShippingCheck::class)->check($this->dataSource()));
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

        $this->offeredOffAmazonShippingBeforeSave = $this->dataSource()->offers_off_amazon_shipping;
        $this->pendingOffAmazonShippingScopes = array_key_exists('off_amazon_shipping_scopes', $data)
            ? array_values((array) $data['off_amazon_shipping_scopes'])
            : null;
        unset($data['off_amazon_shipping_scopes']);
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

        if ($dataSource->isAmazon()) {
            $this->afterAmazonSave($dataSource);

            return;
        }

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
                ->body($preservedConflicts.' '.Str::plural('shipment', $preservedConflicts).' already '.($preservedConflicts === 1 ? 'has' : 'have').' a package and '.($preservedConflicts === 1 ? 'was' : 'were').' not moved to the new mapping. Resolve open location conflicts manually; shipped history remains unchanged.')
                ->send();
        }
    }

    /**
     * The opt-in is checked only when it goes from off to on, and the scope
     * rows of an opted-out connection are kept.
     */
    private function afterAmazonSave(DataSource $dataSource): void
    {
        if ($this->pendingOffAmazonShippingScopes !== null) {
            $dataSource->syncOffAmazonShippingScopes($this->pendingOffAmazonShippingScopes);
        }

        if (! $dataSource->offers_off_amazon_shipping) {
            return;
        }

        if (! $this->offeredOffAmazonShippingBeforeSave) {
            $this->setUpOffAmazonShipping($dataSource);

            // Show the default assignment and the check result just recorded.
            $this->fillForm();

            return;
        }

        if (! $dataSource->offAmazonShippingScopes()->exists()) {
            Notification::make()
                ->warning()
                ->title('No assignment for Amazon Shipping')
                ->body('This connection offers Amazon Shipping for orders from other channels but has no assignment, so no order will use it. Add one below.')
                ->persistent()
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

        $message = 'Amazon SP-API connections give access to customer PII, so Multi-Factor Authentication must be required for all users before this connection can be active. Enable it in App Settings → Security first.';

        Notification::make()
            ->title('Multi-Factor Authentication required')
            ->body($message)
            ->danger()
            ->send();

        $this->addError('data.active', $message);

        throw new Halt;
    }
}
