<?php

namespace App\Filament\Resources\DataSources\Schemas;

use App\Enums\AmazonMarketplace;
use App\Enums\ImportExistingBehavior;
use App\Enums\ScheduleInterval;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\Channel;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\ShippingMethod;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ImportConnectionConfig;
use App\Services\ShipmentImport\RawSqlGuard;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\SshTunnel;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class DataSourceForm
{
    private const DRIVERS = [
        DatabaseSource::class => 'Database (SQL)',
        ShopifySource::class => 'Shopify',
        AmazonSource::class => 'Amazon SP-API',
    ];

    private const CONNECTION_TEST_TIMEOUT_SECONDS = 5;

    private const QUERY_PREVIEW_ROWS = 5;

    private const QUERY_PREVIEW_TTL_SECONDS = 300;

    private const QUERY_PREVIEW_TIMEOUT_SECONDS = 10;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('General')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    Select::make('client_id')
                        ->label('Client')
                        ->options(fn () => Client::orderBy('name')->pluck('name', 'id'))
                        ->nullable()
                        ->searchable()
                        ->helperText('Leave blank to share this source across all clients (single-tenant mode).')
                        ->visible(fn (): bool => self::multiClientEnabled()),

                    Select::make('source_type')
                        ->label('Driver')
                        ->options(self::DRIVERS)
                        ->required()
                        ->live()
                        ->disabled(fn (?DataSource $record) => $record?->exists)
                        ->dehydrated(),

                    Toggle::make('active')
                        ->default(true),

                    Select::make('schedule_interval')
                        ->label('Import Schedule')
                        ->options(ScheduleInterval::class)
                        ->nullable()
                        ->placeholder('Disabled (manual only)')
                        ->helperText('How often this source should automatically run.'),

                    Select::make('settings.on_existing')
                        ->label('Existing Shipments')
                        ->options(ImportExistingBehavior::class)
                        ->default(ImportExistingBehavior::default()->value)
                        ->selectablePlaceholder(false)
                        ->helperText('What to do when an imported shipment already exists. Shipped and voided shipments are never updated.'),
                ])
                ->columns(2),

            // ── Shopify ────────────────────────────────────────────────────────────

            Section::make('Shopify Connection')
                ->schema([
                    Placeholder::make('shopify_oauth_status')
                        ->label('OAuth Status')
                        ->content(fn (?DataSource $record): HtmlString => self::renderShopifyOAuthStatus($record))
                        ->visible(fn (?DataSource $record): bool => (bool) $record?->exists)
                        ->columnSpanFull(),

                    TextInput::make('settings.shop_domain')
                        ->label('Shop Domain')
                        ->placeholder('your-store.myshopify.com')
                        ->helperText('Must be your store\'s .myshopify.com domain — this is where the store\'s API credentials are sent.')
                        ->required()
                        ->maxLength(255)
                        ->rule('regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i'),

                    TextInput::make('settings.client_id')
                        ->label('App Client ID')
                        ->password()
                        ->placeholder(fn (?DataSource $record): string => filled($record?->secret('client_id')) ? 'Configured (leave empty to keep)' : 'Not configured — broker credentials used if available')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('The Shopify app Client ID for this store. Required unless a hosted OAuth broker supplies it.'),

                    TextInput::make('settings.client_secret')
                        ->label('App Client Secret')
                        ->password()
                        ->placeholder(fn (?DataSource $record): string => filled($record?->secret('client_secret')) ? 'Configured (leave empty to keep)' : 'Not configured — broker credentials used if available')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('The Shopify app Client Secret for this store. Required unless a hosted OAuth broker supplies it.'),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === ShopifySource::class)
                ->columns(2),

            Section::make('Shopify Import Settings')
                ->schema([
                    Select::make('settings.channel_name')
                        ->label('Channel')
                        ->options(fn () => Channel::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->helperText('Channel assigned to imported shipments.'),

                    Select::make('settings.shipping_method')
                        ->label('Default Shipping Method')
                        ->options(fn () => ShippingMethod::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                        ->nullable()
                        ->searchable()
                        ->helperText('Leave blank to map per-order via channel aliases.'),

                    Toggle::make('settings.notify_customer')
                        ->label('Notify Customer on Fulfillment')
                        ->default(false),

                    Toggle::make('settings.export_enabled')
                        ->label('Write Fulfillment Back to Shopify')
                        ->default(false),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === ShopifySource::class)
                ->columns(2),

            Section::make('Shopify Location Mapping')
                ->description('Synchronize Shopify locations, then choose where each location is handled in PolyBag. Unmapped fulfillment orders are skipped with an import error.')
                ->schema([
                    Placeholder::make('shopify_location_sync_help')
                        ->label('Location Catalog')
                        ->content('Use “Sync Shopify Locations” above after connecting Shopify. Shopify controls this list; rows cannot be added, removed, or reordered.')
                        ->columnSpanFull(),
                    Repeater::make('locations')
                        ->relationship()
                        ->defaultItems(0)
                        ->schema([
                            TextInput::make('name')
                                ->label('Shopify Location')
                                ->disabled()
                                ->dehydrated(false),
                            Hidden::make('address')->dehydrated(false),
                            Hidden::make('is_active')->dehydrated(false),
                            Hidden::make('location_id')->dehydrated(false),
                            Hidden::make('ignored_at')->dehydrated(false),
                            Placeholder::make('address_display')
                                ->label('Shopify Address')
                                ->content(fn (Get $get): string => self::formatShopifyAddress($get('address'))),
                            Placeholder::make('catalog_status')
                                ->label('Catalog Status')
                                ->content(fn (Get $get): string => $get('is_active') ? 'Active' : 'Inactive — no new work will import'),
                            Select::make('mapping_target')
                                ->label(fn (): string => self::multiLocationEnabled() ? 'PolyBag Location' : 'Handling')
                                ->options(fn (): array => self::shopifyLocationOptions())
                                ->placeholder('Unmapped')
                                ->formatStateUsing(fn (mixed $state, Get $get): ?string => match (true) {
                                    filled($get('ignored_at')) => 'ignore',
                                    filled($get('location_id')) => 'location:'.$get('location_id'),
                                    default => null,
                                })
                                ->searchable(fn (): bool => self::multiLocationEnabled()),
                        ])
                        ->columns(4)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizeShopifyLocationMapping($data))
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get, ?DataSource $record): bool => $get('source_type') === ShopifySource::class && (bool) $record?->exists),

            // ── Amazon ─────────────────────────────────────────────────────────────

            Section::make('Amazon SP-API Connection')
                ->schema([
                    Placeholder::make('amazon_mfa_warning')
                        ->label('')
                        ->content('⚠ Amazon SP-API sources give access to customer PII. Multi-Factor Authentication must be required for all users before this source can be active. Enable it in [App Settings → Authentication]('.SettingsPage::getUrl().').')
                        ->markdown()
                        ->visible(fn (): bool => ! app(SettingsService::class)->get('require_mfa', false))
                        ->columnSpanFull(),

                    Placeholder::make('amazon_oauth_status')
                        ->label('OAuth Status')
                        ->content(fn (?DataSource $record): HtmlString => self::renderAmazonOAuthStatus($record))
                        ->columnSpanFull(),

                    Select::make('settings.marketplace_id')
                        ->label('Marketplace')
                        ->options(fn (?DataSource $record): array => self::amazonMarketplaceOptions($record))
                        ->placeholder('Select a marketplace')
                        ->required()
                        ->searchable()
                        ->helperText('Available marketplaces are discovered after Amazon OAuth. Manual connections may choose from the supported North American marketplaces.'),

                    TextInput::make('settings.refresh_token')
                        ->label('Refresh Token')
                        ->password()
                        ->placeholder(fn (?DataSource $record): string => filled($record?->secret('refresh_token')) ? 'Configured (leave empty to keep)' : 'Not configured')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->required(fn (?DataSource $record): bool => ! $record?->exists && ! app(OAuthService::class)->isBrokerConfigured())
                        ->helperText('Required when no hosted OAuth broker is configured. Otherwise save the source and use Connect Amazon.'),

                    TextInput::make('settings.client_id')
                        ->label('App Client ID')
                        ->password()
                        ->placeholder(fn (?DataSource $record): string => filled($record?->secret('client_id')) ? 'Configured (leave empty to keep)' : 'Not configured — broker credentials used if available')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Your own SP-API application credentials. Required when no hosted OAuth broker is configured — see docs/self-hosting.md.'),

                    TextInput::make('settings.client_secret')
                        ->label('App Client Secret')
                        ->password()
                        ->placeholder(fn (?DataSource $record): string => filled($record?->secret('client_secret')) ? 'Configured (leave empty to keep)' : 'Not configured — broker credentials used if available')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('Your own SP-API application credentials. Required when no hosted OAuth broker is configured — see docs/self-hosting.md.'),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === AmazonSource::class)
                ->columns(2),

            Section::make('Amazon Import Settings')
                ->schema([
                    Select::make('settings.channel_name')
                        ->label('Channel')
                        ->options(fn () => Channel::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->searchable(),

                    Select::make('settings.shipping_method')
                        ->label('Default Shipping Method')
                        ->options(fn () => ShippingMethod::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                        ->nullable()
                        ->searchable(),

                    TextInput::make('settings.lookback_days')
                        ->label('Lookback Days')
                        ->numeric()
                        ->default(30)
                        ->minValue(1)
                        ->maxValue(365),

                    Toggle::make('settings.export_enabled')
                        ->label('Confirm Shipment Back to Amazon')
                        ->default(false),

                    Toggle::make('settings.import_fba_orders')
                        ->label('Import Amazon-Fulfilled (FBA) Orders')
                        ->default(false)
                        ->helperText('Off by default. Amazon picks, packs and ships FBA orders from its own warehouse, so packing one here creates a duplicate shipment and a confirmation Amazon rejects. Turn this on only if you want them visible for reference — imported FBA orders are badged and cannot be packed or exported.')
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === AmazonSource::class)
                ->columns(2),

            // ── Database ───────────────────────────────────────────────────────────

            Section::make('Database Connection')
                ->key('database_connection', isInheritable: false)
                ->schema([
                    Select::make('settings.db_driver')
                        ->label('Driver')
                        ->options(ImportConnectionConfig::DRIVERS)
                        ->default('mysql')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set(
                            'settings.db_port',
                            ImportConnectionConfig::defaultPort($state),
                        )),

                    TextInput::make('settings.db_host')
                        ->label('Host')
                        ->required(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('settings.db_driver')))
                        ->visible(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('settings.db_driver')))
                        ->maxLength(255),

                    TextInput::make('settings.db_port')
                        ->label('Port')
                        ->numeric()
                        ->default(3306)
                        ->visible(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('settings.db_driver'))),

                    TextInput::make('settings.db_schema')
                        ->label('Schema')
                        ->placeholder('public')
                        ->nullable()
                        ->maxLength(255)
                        ->helperText('Sets the connection search_path. Leave blank for "public".')
                        ->visible(fn (Get $get): bool => $get('settings.db_driver') === 'pgsql'),

                    Toggle::make('settings.db_encrypt')
                        ->label('Encrypt Connection')
                        ->default(true)
                        // A record saved before this field existed has no stored
                        // value; treat that as on, so editing it cannot silently
                        // downgrade the connection to plaintext.
                        ->afterStateHydrated(fn (Toggle $component, $state): Toggle => $component->state($state ?? true))
                        ->helperText('ODBC Driver 18 encrypts by default. Turn this off only for a server that cannot do TLS.')
                        ->visible(fn (Get $get): bool => $get('settings.db_driver') === 'sqlsrv'),

                    Toggle::make('settings.db_trust_server_certificate')
                        ->label('Trust Server Certificate')
                        ->default(false)
                        ->helperText('Required for a server using a self-signed certificate, which is common on-premise. Skips certificate validation.')
                        ->visible(fn (Get $get): bool => $get('settings.db_driver') === 'sqlsrv'),

                    TextInput::make('settings.db_database')
                        ->label(fn (Get $get): string => $get('settings.db_driver') === 'sqlite' ? 'Database File Path' : 'Database')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('settings.db_username')
                        ->label('Username')
                        ->required(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('settings.db_driver')))
                        ->visible(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('settings.db_driver')))
                        ->maxLength(255),

                    TextInput::make('settings.db_password')
                        ->label('Password')
                        ->visible(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('settings.db_driver')))
                        ->password()
                        ->placeholder(fn (?DataSource $record): string => filled($record?->secret('db_password')) ? 'Configured (leave empty to keep)' : 'Not configured')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state): bool => filled($state)),
                ])
                ->footerActions([
                    Action::make('test_db_connection')
                        ->label('Test Connection')
                        ->icon(Heroicon::Signal)
                        ->color('gray')
                        ->action(function (Get $get, ?DataSource $record): void {
                            $tunnel = null;
                            $connName = null;

                            try {
                                ['tunnel' => $tunnel, 'conn_name' => $connName] = self::openTestConnection($get, $record);

                                Notification::make()
                                    ->success()
                                    ->title('Connection successful')
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Connection failed')
                                    ->body($e->getMessage())
                                    ->send();
                            } finally {
                                $tunnel?->close();
                                if ($connName) {
                                    DB::purge($connName);
                                }
                            }
                        }),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === DatabaseSource::class)
                ->columns(2),

            Section::make('Database Query')
                ->key('database_query', isInheritable: false)
                ->schema([
                    TextInput::make('settings.shipments_table')
                        ->label('Shipments Table')
                        ->default('shipments')
                        ->maxLength(255),

                    TextInput::make('settings.shipment_items_table')
                        ->label('Items Table')
                        ->default('shipment_items')
                        ->maxLength(255),

                    TextInput::make('settings.client_column')
                        ->label('Client Column')
                        ->nullable()
                        ->maxLength(255)
                        ->helperText('Column in each row that identifies the client (matched by name). When set, the Client field above is ignored and each row maps to its own client.')
                        ->columnSpanFull()
                        ->visible(fn (): bool => self::multiClientEnabled()),

                    Textarea::make('settings.shipments_query')
                        ->label('Custom Shipments Query')
                        ->nullable()
                        ->rows(3)
                        ->rule(RawSqlGuard::rule(RawSqlGuard::READ, 'Custom Shipments Query'))
                        ->helperText('Optional. Overrides table + filters. Leave blank to use table-based query. ⚠️ Runs verbatim against the configured database — must be a single SELECT statement.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? str_replace("\u{00A0}", ' ', $state) : $state)
                        ->columnSpanFull(),

                    Textarea::make('settings.shipment_items_query')
                        ->label('Custom Items Query')
                        ->nullable()
                        ->rows(3)
                        ->rule(RawSqlGuard::rule(RawSqlGuard::READ, 'Custom Items Query'))
                        ->helperText('Use :shipment_reference as the placeholder. Leave blank to query by shipment_id. ⚠️ Runs verbatim against the configured database — must be a single SELECT statement.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? str_replace("\u{00A0}", ' ', $state) : $state)
                        ->columnSpanFull(),

                    Toggle::make('settings.mark_exported_enabled')
                        ->label('Mark Exported After Import')
                        ->live(),

                    Textarea::make('settings.mark_exported_query')
                        ->label('Mark Exported Query')
                        ->nullable()
                        ->rows(2)
                        ->rule(RawSqlGuard::rule(RawSqlGuard::MARK_EXPORTED, 'Mark Exported Query'))
                        ->helperText('Use :shipment_reference as placeholder. ⚠️ Runs automatically on every import against the configured database — must be a single UPDATE or INSERT statement.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? str_replace("\u{00A0}", ' ', $state) : $state)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.mark_exported_enabled')),

                    TextInput::make('settings.max_affected_rows')
                        ->label('Max Affected Rows Per Write')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->helperText('Safety cap for the Mark Exported and Export queries: a write that would affect more rows than this is rolled back. These run once per record, so 1 is correct unless your source stores multiple rows per shipment.')
                        ->columnSpanFull(),
                ])
                ->footerActions([
                    Action::make('test_queries')
                        ->label('Preview Queries')
                        ->icon(Heroicon::CheckCircle)
                        ->color('gray')
                        ->modalHeading('Query preview')
                        ->modalDescription('Runs the read queries against the configured database and shows the first rows, raw and mapped. The write queries are parse-checked only — a preview never writes.')
                        ->modalWidth('7xl')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        // Run the queries once, when the action is mounted. modalContent
                        // is evaluated again on every Livewire render while the modal is
                        // open — a field change, a poll — and re-running the operator's
                        // SQL against a customer database, re-auditing it each time, is
                        // not what one click asked for.
                        ->mountUsing(function (HasActions $livewire, Get $get, ?DataSource $record): void {
                            $token = Str::uuid()->toString();

                            Cache::put(
                                self::queryPreviewCacheKey($token),
                                self::buildQueryPreview($get, $record),
                                self::QUERY_PREVIEW_TTL_SECONDS,
                            );

                            $livewire->mergeMountedActionArguments(['preview_token' => $token]);
                        })
                        ->modalContent(fn (Action $action): View => view(
                            'filament.resources.data-sources.query-preview',
                            self::queryPreview($action->getArguments()['preview_token'] ?? null),
                        )),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === DatabaseSource::class)
                ->columns(2),

            Section::make('Database Export')
                ->schema([
                    Toggle::make('settings.export_enabled')
                        ->label('Write Tracking Back to Source Database')
                        ->live()
                        ->default(false),

                    Toggle::make('global_export')
                        ->label('Global Export Destination')
                        ->helperText('When enabled, all shipped packages write tracking data here — regardless of which source they came from, including manual shipments.')
                        ->default(false)
                        ->visible(fn (Get $get): bool => self::multiClientEnabled() && (bool) $get('settings.export_enabled')),

                    Textarea::make('settings.export_query')
                        ->label('Export Query')
                        ->nullable()
                        ->rows(3)
                        ->rule(RawSqlGuard::rule(RawSqlGuard::EXPORT, 'Export Query'))
                        ->helperText('Available parameters: :tracking_number, :carrier, :service, :weight, :cost, :shipment_reference. ⚠️ Runs automatically for every shipped package against the configured database — must be a single INSERT or UPDATE statement.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? str_replace("\u{00A0}", ' ', $state) : $state)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.export_enabled')),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === DatabaseSource::class)
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            Section::make('SSH Tunnel')
                ->schema([
                    Toggle::make('settings.ssh_enabled')
                        ->label('Enable SSH Tunnel')
                        ->live()
                        ->default(false),

                    TextInput::make('settings.ssh_host')
                        ->label('SSH Host')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('settings.ssh_port')
                        ->label('SSH Port')
                        ->numeric()
                        ->default(22)
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('settings.ssh_user')
                        ->label('SSH User')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('settings.ssh_remote_host')
                        ->label('Remote DB Host')
                        ->maxLength(255)
                        ->helperText('Override if DB runs on a different host than the SSH server.')
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('settings.ssh_remote_port')
                        ->label('Remote DB Port')
                        ->numeric()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    Textarea::make('settings.ssh_host_key')
                        ->label('SSH Host Key')
                        ->nullable()
                        ->rows(2)
                        ->helperText('Host key of the SSH Host above (not this PolyBag server). Run `ssh-keyscan -t ed25519 <ssh-host>` against that host and paste the line as-is — its hostname must match SSH Host exactly.')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('ssh_public_key')
                        ->label('SSH Public Key')
                        ->helperText('Add this to ~/.ssh/authorized_keys on the SSH host. Optionally append permitopen="host:port" to restrict forwarding to a specific server.')
                        ->readOnly()
                        ->copyable()
                        ->dehydrated(false)
                        ->formatStateUsing(function (): string {
                            $pubKeyPath = storage_path('app/private/ssh/id_ed25519.pub');
                            if (! file_exists($pubKeyPath)) {
                                return 'SSH key not generated. Run: php artisan app:generate-ssh-key';
                            }

                            return 'restrict,port-forwarding '.trim(file_get_contents($pubKeyPath));
                        })
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === DatabaseSource::class)
                ->columns(2)
                ->collapsible()
                ->collapsed(),
        ]);
    }

    private static function multiClientEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_client_enabled', false);
    }

    private static function multiLocationEnabled(): bool
    {
        return (bool) app(SettingsService::class)->get('multi_location_enabled', false);
    }

    /** @return array<string, string> */
    private static function shopifyLocationOptions(): array
    {
        if (self::multiLocationEnabled()) {
            return ['ignore' => 'Ignore this location'] + Location::active()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Location $location): array => ["location:{$location->id}" => $location->name])
                ->all();
        }

        $default = Location::getDefault();

        return ['ignore' => 'Ignore this location'] + ($default?->active
            ? ["location:{$default->id}" => "Handled here — {$default->name}"]
            : []);
    }

    /** @param array<string, mixed>|null $address */
    private static function formatShopifyAddress(?array $address): string
    {
        if ($address === null) {
            return '—';
        }

        return collect([
            $address['address1'] ?? null,
            $address['address2'] ?? null,
            trim(implode(' ', array_filter([
                $address['city'] ?? null,
                $address['provinceCode'] ?? $address['province'] ?? null,
                $address['zip'] ?? null,
            ]))),
            $address['countryCode'] ?? null,
        ])->filter()->implode(', ') ?: '—';
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function normalizeShopifyLocationMapping(array $data): array
    {
        $target = $data['mapping_target'] ?? null;
        unset($data['mapping_target']);

        if ($target === 'ignore') {
            $data['location_id'] = null;
            $data['ignored_at'] = now();
        } elseif (is_string($target) && str_starts_with($target, 'location:')) {
            $data['location_id'] = (int) str($target)->after('location:')->toString();
            $data['ignored_at'] = null;
        } else {
            $data['location_id'] = null;
            $data['ignored_at'] = null;
        }

        return $data;
    }

    /**
     * The preview built when the action was mounted, addressed by the random
     * token held in the mounted action's arguments. Previewed rows are customer
     * order data, so they stay server-side and only the token travels in the
     * Livewire snapshot; the key is scoped to the viewer as well, so a token
     * lifted from one session buys nothing in another.
     *
     * A modal left open past the TTL asks for a fresh click rather than quietly
     * running the operator's SQL again. Rebuilding here would put every later
     * render of that modal back on the source database — the same defect the
     * mount-time build exists to prevent, just deferred by the TTL.
     *
     * @return array<string, mixed>
     */
    private static function queryPreview(?string $token): array
    {
        $cached = filled($token) ? Cache::get(self::queryPreviewCacheKey($token)) : null;

        if (is_array($cached)) {
            return $cached;
        }

        return [
            'preview' => null,
            'writeChecks' => [],
            'connectionError' => null,
            'expired' => true,
            'previewRows' => self::QUERY_PREVIEW_ROWS,
        ];
    }

    private static function queryPreviewCacheKey(string $token): string
    {
        return 'data-source-query-preview:'.auth()->id().':'.$token;
    }

    /**
     * Execute the read queries against the connection described by the current
     * form state and build the preview modal's view data: a bounded sample of rows
     * with the field mapping applied, plus a parse check for the write queries.
     *
     * @return array<string, mixed>
     */
    private static function buildQueryPreview(Get $get, ?DataSource $record): array
    {
        $tunnel = null;
        $connName = null;

        try {
            ['pdo' => $pdo, 'tunnel' => $tunnel, 'conn_name' => $connName] = self::openTestConnection($get, $record);

            // openTestConnection only bounds how long connecting may take. These
            // queries then run inside the web request, so they need a bound of
            // their own — the operator's SQL is arbitrary, and a lock or a bad
            // plan would otherwise hold the worker until the proxy 504s.
            ImportConnectionConfig::applyStatementTimeout(
                $pdo,
                config("database.connections.{$connName}.driver"),
                self::QUERY_PREVIEW_TIMEOUT_SECONDS,
            );

            // The form exposes only a subset of the stored settings, so the saved
            // record supplies the rest — notably field_mapping, which has no form
            // field but decides what the mapped preview columns are.
            $settings = array_merge($record->settings ?? [], (array) ($get('settings') ?? []));

            $source = new DatabaseSource(
                DataSourceFactory::databaseConfigFor($settings, $connName, $record?->id),
            );

            return [
                'preview' => $source->preview(self::QUERY_PREVIEW_ROWS),
                'writeChecks' => self::checkWriteQueries($pdo, $get),
                'connectionError' => null,
                'expired' => false,
                'previewRows' => self::QUERY_PREVIEW_ROWS,
            ];
        } catch (\Throwable $e) {
            return [
                'preview' => null,
                'writeChecks' => [],
                'connectionError' => $e->getMessage(),
                'expired' => false,
                'previewRows' => self::QUERY_PREVIEW_ROWS,
            ];
        } finally {
            $tunnel?->close();
            if ($connName) {
                DB::purge($connName);
            }
        }
    }

    /**
     * Parse-check the write queries. A preview must never write to a customer
     * database, so these are prepared and discarded rather than executed — which
     * only means something with emulated prepares off, hence the MySQL attribute.
     *
     * @return array<string, string|null> Query label => error message, or null when it parses.
     */
    private static function checkWriteQueries(\PDO $pdo, Get $get): array
    {
        if (($get('settings.db_driver') ?? 'mysql') === 'mysql') {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        }

        $queries = array_filter([
            'Mark exported query' => $get('settings.mark_exported_query'),
            'Export query' => $get('settings.export_query'),
        ]);

        $results = [];

        foreach ($queries as $label => $sql) {
            try {
                $pdo->prepare(str_replace("\u{00A0}", ' ', $sql));
                $results[$label] = null;
            } catch (\PDOException $e) {
                $results[$label] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Open a temporary PDO connection from the current form state, including an
     * SSH tunnel if configured. Falls back to the saved record's password when
     * the form field is blank (edit page); on the create page the record is null
     * and the form state is all we have. Returns the PDO, optional tunnel, and
     * connection name. The caller is responsible for closing the tunnel and
     * purging the connection.
     *
     * @return array{pdo: \PDO, tunnel: ?SshTunnel, conn_name: string}
     */
    private static function openTestConnection(Get $get, ?DataSource $record): array
    {
        $connName = 'import_test_'.($record?->id ?? uniqid());
        $password = filled($get('settings.db_password'))
            ? $get('settings.db_password')
            : $record?->secret('db_password');

        $settings = [
            'db_driver' => $get('settings.db_driver') ?? 'mysql',
            'db_host' => $get('settings.db_host') ?? '127.0.0.1',
            'db_port' => $get('settings.db_port'),
            'db_database' => $get('settings.db_database'),
            'db_username' => $get('settings.db_username'),
            'db_schema' => $get('settings.db_schema'),
            'db_encrypt' => $get('settings.db_encrypt') ?? true,
            'db_trust_server_certificate' => $get('settings.db_trust_server_certificate') ?? false,
        ];

        config([
            // Keep the connect timeout well under the reverse proxy's read timeout
            // so a bad host/port fails with a catchable exception instead of
            // hanging until the proxy itself returns a 504.
            "database.connections.{$connName}" => ImportConnectionConfig::withConnectTimeout(
                ImportConnectionConfig::build($settings, $password),
                10,
            ),
        ]);
        DB::purge($connName);

        $tunnel = null;

        if ($get('settings.ssh_enabled')) {
            $keyPath = storage_path('app/private/ssh/id_ed25519');
            if (! file_exists($keyPath)) {
                throw new \RuntimeException('SSH key not found. Run `php artisan app:generate-ssh-key` first.');
            }

            $tunnel = SshTunnel::fromConfig([
                'ssh_host' => $get('settings.ssh_host') ?? '',
                'ssh_port' => (int) ($get('settings.ssh_port') ?? 22),
                'ssh_user' => $get('settings.ssh_user') ?? '',
                'ssh_key' => $keyPath,
                'remote_host' => $get('settings.ssh_remote_host') ?: ($get('settings.db_host') ?? '127.0.0.1'),
                'remote_port' => (int) ($get('settings.ssh_remote_port') ?: ($get('settings.db_port')
                    ?: ImportConnectionConfig::defaultPort($get('settings.db_driver') ?? 'mysql'))),
                'known_hosts_entry' => $get('settings.ssh_host_key') ?? '',
                'known_hosts_file' => storage_path('app/private/ssh/import_known_hosts'),
            ]);

            $localPort = $tunnel->open();
            config([
                "database.connections.{$connName}.host" => '127.0.0.1',
                "database.connections.{$connName}.port" => $localPort,
            ]);
            DB::purge($connName);
        }

        // PDO/mysqlnd's own connect timeout can't be relied on to bound how long a
        // bad host takes to fail — it's been observed to hang well past a minute,
        // long enough for the reverse proxy to give up first with an ugly 504. A
        // raw TCP probe with an explicit timeout fails fast and predictably instead.
        self::assertHostReachable(
            config("database.connections.{$connName}.driver"),
            config("database.connections.{$connName}.host"),
            config("database.connections.{$connName}.port"),
        );

        $pdo = DB::connection($connName)->getPdo();

        return ['pdo' => $pdo, 'tunnel' => $tunnel, 'conn_name' => $connName];
    }

    private static function assertHostReachable(?string $driver, ?string $host, mixed $port): void
    {
        if (! ImportConnectionConfig::usesHost($driver) || ! $host) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            self::CONNECTION_TEST_TIMEOUT_SECONDS,
        );

        if (! $socket) {
            throw new \RuntimeException(sprintf(
                'Could not reach %s:%s within %ds.%s',
                $host,
                $port,
                self::CONNECTION_TEST_TIMEOUT_SECONDS,
                $errstr ? " ({$errstr})" : '',
            ));
        }

        fclose($socket);
    }

    private static function renderShopifyOAuthStatus(?DataSource $record): HtmlString
    {
        if (! $record?->exists) {
            return new HtmlString('');
        }

        $connected = app(OAuthService::class)->isDataSourceConnected($record);
        $connectedAt = $record->settings['oauth_connected_at'] ?? null;
        $scopes = $record->settings['oauth_scopes'] ?? null;

        return new HtmlString(
            view('filament.pages.settings.oauth-status', [
                'connected' => $connected,
                'time' => $connectedAt ? Carbon::parse($connectedAt)->diffForHumans() : null,
                'scopes' => $scopes,
            ])->render()
        );
    }

    private static function renderAmazonOAuthStatus(?DataSource $record): HtmlString
    {
        if (! $record?->exists) {
            return new HtmlString('Save the data source, then use Connect Amazon.');
        }

        $connected = app(OAuthService::class)->isDataSourceConnected($record);
        $connectedAt = $record->settings['oauth_connected_at'] ?? null;

        return new HtmlString(
            view('filament.pages.settings.oauth-status', [
                'connected' => $connected,
                'time' => $connectedAt ? Carbon::parse($connectedAt)->diffForHumans() : null,
                'scopes' => null,
            ])->render()
        );
    }

    /** @return array<string, string> */
    private static function amazonMarketplaceOptions(?DataSource $record): array
    {
        $settings = $record->settings ?? [];
        $marketplaces = $settings['amazon_marketplaces'] ?? null;

        $options = AmazonMarketplace::options();

        if (is_array($marketplaces)) {
            foreach ($marketplaces as $marketplace) {
                if (! is_array($marketplace) || ! is_string($marketplace['id'] ?? null)) {
                    continue;
                }

                $knownMarketplace = AmazonMarketplace::tryFrom($marketplace['id']);
                $name = is_string($marketplace['name'] ?? null)
                    ? $marketplace['name']
                    : $knownMarketplace?->label();
                $countryCode = is_string($marketplace['country_code'] ?? null)
                    ? $marketplace['country_code']
                    : $knownMarketplace?->countryCode();

                if ($name === null || $countryCode === null) {
                    continue;
                }

                $label = "{$name} ({$countryCode})";

                if ((bool) ($marketplace['has_suspended_listings'] ?? false)) {
                    $label .= ' — Listings suspended';
                }

                $options[$marketplace['id']] = $label;
            }
        }

        $currentMarketplaceId = $settings['marketplace_id'] ?? null;

        if (is_string($currentMarketplaceId) && $currentMarketplaceId !== '' && ! array_key_exists($currentMarketplaceId, $options)) {
            $options[$currentMarketplaceId] = "{$currentMarketplaceId} (existing selection)";
        }

        return $options;
    }
}
