<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Exceptions\FedexRegistrationMaxRetriesException;
use App\Filament\Support\AddressForm;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Carrier;
use App\Models\Location;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Services\AddressReferenceService;
use App\Services\FedexRegistrationService;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\SshTunnel;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Saloon\Exceptions\Request\Statuses\ForbiddenException;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'App Settings';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.settings';

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Admin);
    }

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    // FedEx registration wizard transient state
    public bool $fedexEulaAccepted = false;

    public ?string $fedexAccountAuthToken = null;

    public ?string $fedexFactor2Method = null;

    public ?string $fedexMaskedEmail = null;

    public ?string $fedexMaskedPhone = null;

    /** @var string[] */
    public array $fedexSecureCodeOptions = [];

    public bool $fedexInvoiceAvailable = false;

    /** @var string[] */
    public array $fedexLockedFactor2Methods = [];

    public bool $fedexSupportFallbackActive = false;

    /**
     * Encrypted credential fields and their setting keys.
     *
     * @var array<string, string>
     */
    private const ENCRYPTED_FIELDS = [
        'usps_client_id' => 'usps.client_id',
        'usps_client_secret' => 'usps.client_secret',
        'fedex_api_key' => 'fedex.api_key',
        'fedex_api_secret' => 'fedex.api_secret',
        'fedex_sandbox_api_key' => 'fedex.sandbox_api_key',
        'fedex_sandbox_api_secret' => 'fedex.sandbox_api_secret',
        'ups_client_id' => 'ups.client_id',
        'ups_client_secret' => 'ups.client_secret',
        'shopify_client_id' => 'shopify.client_id',
        'shopify_client_secret' => 'shopify.client_secret',
        'amazon_client_id' => 'amazon.client_id',
        'amazon_client_secret' => 'amazon.client_secret',
        'amazon_refresh_token' => 'amazon.refresh_token',
        'import_db_password' => 'import.db_password',
    ];

    /**
     * Non-encrypted credential fields and their setting keys.
     *
     * @var array<string, string>
     */
    private const CREDENTIAL_FIELDS = [
        'usps_crid' => 'usps.crid',
        'usps_mid' => 'usps.mid',
        'fedex_account_number' => 'fedex.account_number',
        'ups_account_number' => 'ups.account_number',
        'shopify_shop_domain' => 'shopify.shop_domain',
        'shopify_api_version' => 'shopify.api_version',
        'amazon_marketplace_id' => 'amazon.marketplace_id',
        'import_db_driver' => 'import.db_driver',
        'import_db_host' => 'import.db_host',
        'import_db_port' => 'import.db_port',
        'import_db_database' => 'import.db_database',
        'import_db_username' => 'import.db_username',
        'import_ssh_host' => 'import.ssh_host',
        'import_ssh_port' => 'import.ssh_port',
        'import_ssh_user' => 'import.ssh_user',
        'import_ssh_remote_host' => 'import.ssh_remote_host',
        'import_ssh_remote_port' => 'import.ssh_remote_port',
        'import_ssh_host_key' => 'import.ssh_host_key',
    ];

    public function mount(): void
    {
        // Show flash notification from OAuth callback redirect
        if ($notification = session('oauth_notification')) {
            Notification::make()
                ->{$notification['status']}()
                ->title($notification['title'])
                ->send();
        }

        $this->form->fill([
            'company_name' => app(SettingsService::class)->get('company_name', ''),
            'packing_validation_enabled' => app(SettingsService::class)->get('packing_validation_enabled', true),
            'transparency_enabled' => app(SettingsService::class)->get('transparency_enabled', true),
            'batch_shipping_enabled' => app(SettingsService::class)->get('batch_shipping_enabled', true),
            'manual_shipping_enabled' => app(SettingsService::class)->get('manual_shipping_enabled', true),
            'carrier_api_timeout' => app(SettingsService::class)->get('carrier_api_timeout', 15),
            'import_source' => app(SettingsService::class)->get('import_source', 'database'),
            'audit_log_retention_days' => app(SettingsService::class)->get('audit_log_retention_days', 365),
            'rate_quote_retention_days' => app(SettingsService::class)->get('rate_quote_retention_days', 60),
            'pii_retention_days' => app(SettingsService::class)->get('pii_retention_days', 90),
            'archiving_enabled' => app(SettingsService::class)->get('archiving_enabled', false),
            'archive_retention_days' => app(SettingsService::class)->get('archive_retention_days', 365),
            'password_min_length' => app(SettingsService::class)->get('password_min_length', 8),
            'password_require_mixed_case' => app(SettingsService::class)->get('password_require_mixed_case', true),
            'password_require_numbers' => app(SettingsService::class)->get('password_require_numbers', true),
            'password_require_symbols' => app(SettingsService::class)->get('password_require_symbols', false),
            'password_expiration_days' => app(SettingsService::class)->get('password_expiration_days', 0),
            'google_sso_enabled' => app(SettingsService::class)->get('google_sso_enabled', false),
            'sandbox_mode' => app(SettingsService::class)->get('sandbox_mode', false),
            'suppress_printing' => app(SettingsService::class)->get('suppress_printing', false),

            // Non-encrypted credential fields get their current values
            'usps_crid' => app(SettingsService::class)->get('usps.crid', ''),
            'usps_mid' => app(SettingsService::class)->get('usps.mid', ''),
            'fedex_account_number' => app(SettingsService::class)->get('fedex.account_number', ''),
            'ups_account_number' => app(SettingsService::class)->get('ups.account_number', ''),
            'shopify_shop_domain' => app(SettingsService::class)->get('shopify.shop_domain', ''),
            'shopify_api_version' => app(SettingsService::class)->get('shopify.api_version', '2025-01'),
            'shopify_import_enabled' => app(SettingsService::class)->get('shopify.import_enabled', false),
            'shopify_export_enabled' => app(SettingsService::class)->get('shopify.export_enabled', false),
            'shopify_channel_name' => app(SettingsService::class)->get('shopify.channel_name', 'Shopify'),
            'shopify_shipping_method' => app(SettingsService::class)->get('shopify.shipping_method', ''),
            'shopify_notify_customer' => app(SettingsService::class)->get('shopify.notify_customer', false),
            'amazon_marketplace_id' => app(SettingsService::class)->get('amazon.marketplace_id', 'ATVPDKIKX0DER'),
            'amazon_import_enabled' => app(SettingsService::class)->get('amazon.import_enabled', false),
            'amazon_export_enabled' => app(SettingsService::class)->get('amazon.export_enabled', false),
            'amazon_channel_name' => app(SettingsService::class)->get('amazon.channel_name', 'Amazon'),
            'amazon_shipping_method' => app(SettingsService::class)->get('amazon.shipping_method', ''),
            'amazon_lookback_days' => app(SettingsService::class)->get('amazon.lookback_days', 30),

            // Database import
            'import_db_driver' => app(SettingsService::class)->get('import.db_driver', 'mysql'),
            'import_db_host' => app(SettingsService::class)->get('import.db_host', ''),
            'import_db_port' => app(SettingsService::class)->get('import.db_port', ''),
            'import_db_database' => app(SettingsService::class)->get('import.db_database', ''),
            'import_db_username' => app(SettingsService::class)->get('import.db_username', ''),
            'import_shipments_query' => app(SettingsService::class)->get('import.shipments_query', ''),
            'import_shipment_items_query' => app(SettingsService::class)->get('import.shipment_items_query', ''),
            'import_export_query' => app(SettingsService::class)->get('import.export_query', ''),
            'import_ssh_enabled' => (bool) app(SettingsService::class)->get('import.ssh_enabled', false),
            'import_ssh_host' => app(SettingsService::class)->get('import.ssh_host', ''),
            'import_ssh_port' => app(SettingsService::class)->get('import.ssh_port', '22'),
            'import_ssh_user' => app(SettingsService::class)->get('import.ssh_user', ''),
            'import_ssh_remote_host' => app(SettingsService::class)->get('import.ssh_remote_host', ''),
            'import_ssh_remote_port' => app(SettingsService::class)->get('import.ssh_remote_port', ''),
            'import_ssh_host_key' => app(SettingsService::class)->get('import.ssh_host_key', ''),
            'ssh_public_key' => $this->getSshPublicKey(),

            // Encrypted fields are left empty — placeholder shows status
        ]);
    }

    /**
     * Build the Shopify credential fields, wrapped in a collapsible section for hosted mode.
     *
     * @return array<Component>
     */
    private function upsCredentialFields(): array
    {
        $fields = [
            TextInput::make('ups_client_id')
                ->label('Client ID')
                ->password()
                ->placeholder(fn () => $this->getCredentialPlaceholder('ups.client_id', 'services.ups.client_id')),
            TextInput::make('ups_client_secret')
                ->label('Client Secret')
                ->password()
                ->placeholder(fn () => $this->getCredentialPlaceholder('ups.client_secret', 'services.ups.client_secret')),
        ];

        if (config('services.oauth.broker_url')) {
            return [
                Section::make('Custom App Credentials')
                    ->description('Override the shared OAuth credentials with your own UPS API credentials.')
                    ->collapsed()
                    ->schema($fields)
                    ->columns(2),
            ];
        }

        return $fields;
    }

    private function shopifyCredentialFields(): array
    {
        $fields = [
            TextInput::make('shopify_client_id')
                ->label('Client ID')
                ->password()
                ->placeholder(fn () => $this->getCredentialPlaceholder('shopify.client_id', 'services.shopify.client_id')),
            TextInput::make('shopify_client_secret')
                ->label('Client Secret')
                ->password()
                ->placeholder(fn () => $this->getCredentialPlaceholder('shopify.client_secret', 'services.shopify.client_secret')),
        ];

        if (config('services.oauth.broker_url')) {
            return [
                Section::make('Custom App Credentials')
                    ->description('Override the shared OAuth credentials with a custom app created in your Shopify admin.')
                    ->collapsed()
                    ->schema($fields)
                    ->columns(2),
            ];
        }

        return $fields;
    }

    public function resendFedexPin(): void
    {
        if (! $this->fedexAccountAuthToken || ! $this->fedexFactor2Method) {
            return;
        }

        try {
            app(FedexRegistrationService::class)->sendPin($this->fedexAccountAuthToken, $this->fedexFactor2Method);
            Notification::make()->success()->title('PIN resent.')->send();
        } catch (\Throwable $e) {
            $this->notifyFedexRegistrationError($e);
        }
    }

    public function closeFedexRegistrationModal(): void
    {
        $this->unmountAction(false);
    }

    private function resetFedexRegistrationState(): void
    {
        $this->fedexEulaAccepted = false;
        $this->fedexAccountAuthToken = null;
        $this->fedexFactor2Method = null;
        $this->fedexMaskedEmail = null;
        $this->fedexMaskedPhone = null;
        $this->fedexSecureCodeOptions = [];
        $this->fedexInvoiceAvailable = false;
        $this->fedexLockedFactor2Methods = [];
        $this->fedexSupportFallbackActive = false;
    }

    private function renderFedexWizardSubmitAction(): HtmlString
    {
        if ($this->fedexSupportFallbackActive) {
            return new HtmlString(
                Blade::render(
                    '<x-filament::button type="button" wire:click="closeFedexRegistrationModal" color="gray">Close</x-filament::button>'
                )
            );
        }

        return new HtmlString(
            Blade::render(
                '<x-filament::button type="button" wire:click="callMountedAction">Add Account</x-filament::button>'
            )
        );
    }

    /**
     * @return array<string, string>
     */
    public function getFedexAvailableVerificationOptions(): array
    {
        $options = [];

        foreach ($this->fedexSecureCodeOptions as $code) {
            if (in_array($code, $this->fedexLockedFactor2Methods, strict: true)) {
                continue;
            }

            $options[$code] = match ($code) {
                'SMS' => 'PIN via SMS'.($this->fedexMaskedPhone ? " ({$this->fedexMaskedPhone})" : ''),
                'CALL' => 'PIN via Phone Call'.($this->fedexMaskedPhone ? " ({$this->fedexMaskedPhone})" : ''),
                'EMAIL' => 'PIN via Email'.($this->fedexMaskedEmail ? " ({$this->fedexMaskedEmail})" : ''),
                default => $code,
            };
        }

        if ($this->fedexInvoiceAvailable && ! in_array('INVOICE', $this->fedexLockedFactor2Methods, strict: true)) {
            $options['INVOICE'] = 'Invoice Validation';
        }

        return $options;
    }

    public function hasAvailableFedexFactor2Methods(): bool
    {
        return $this->getFedexAvailableVerificationOptions() !== [];
    }

    /**
     * @param  array{accountAuthToken: string, email: ?string, phoneNumber: ?string, options: array{invoice: bool, secureCode: array<int, string>}}  $result
     */
    private function storeFedexVerificationState(array $result): void
    {
        $this->fedexAccountAuthToken = $result['accountAuthToken'];
        $this->fedexMaskedEmail = $result['email'];
        $this->fedexMaskedPhone = $result['phoneNumber'];
        $this->fedexSecureCodeOptions = $result['options']['secureCode'];
        $this->fedexInvoiceAvailable = $result['options']['invoice'];
        $this->fedexLockedFactor2Methods = [];
        $this->fedexSupportFallbackActive = false;

        $this->refreshMountedFedexAction();
    }

    private function completeFedexRegistration(string $accountNumber, string $childKey, string $childSecret): void
    {
        app(FedexRegistrationService::class)->activateChildCredentials($childKey, $childSecret);
        app(SettingsService::class)->set('fedex.account_number', $accountNumber, group: 'fedex');
        $this->fedex_account_number = $accountNumber;
    }

    private function refreshMountedFedexAction(): void
    {
        if (empty($this->mountedActions ?? [])) {
            return;
        }

        $this->cachedMountedActions = null;

        foreach ($this->cachedSchemas as $schemaName => $schema) {
            if (str($schemaName)->startsWith('mountedActionSchema')) {
                unset($this->cachedSchemas[$schemaName]);
            }
        }

        $this->cacheMountedActions($this->mountedActions);
    }

    private function notifyFedexRegistrationError(\Throwable $exception): void
    {
        Notification::make()
            ->danger()
            ->title('FedEx Error')
            ->body($exception->getMessage())
            ->send();
    }

    private function handleFedexRegistrationLockout(FedexRegistrationMaxRetriesException $exception): void
    {
        $this->fedexLockedFactor2Methods = array_values(array_unique([
            ...$this->fedexLockedFactor2Methods,
            ...$exception->lockedMethods,
        ]));
        $this->fedexSupportFallbackActive = true;

        $mountedActionIndex = array_key_last($this->mountedActions ?? []);

        if ($mountedActionIndex === null) {
            return;
        }

        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_factor2_method', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_pin', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_invoice_number', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_invoice_date', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_invoice_amount', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_invoice_currency', 'USD');
        $this->refreshMountedFedexAction();
    }

    private function isFedexAccountConnected(): bool
    {
        return filled(app(SettingsService::class)->get('fedex.child_key'));
    }

    private function renderFedexAccountStatus(): HtmlString
    {
        $settings = app(SettingsService::class);

        if (filled($settings->get('fedex.child_key'))) {
            $isSandbox = $settings->get('sandbox_mode', false);
            $env = $isSandbox ? 'sandbox' : 'production';

            return new HtmlString("<span class=\"text-success-600 dark:text-success-400 font-medium\">Connected</span> — {$env} credentials provisioned via Account Registration");
        }

        return new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not connected</span> — click Connect FedEx Account to provision credentials');
    }

    private function isBrokerConfigured(): bool
    {
        return config('services.oauth.broker_url')
            && config('services.oauth.broker_secret')
            && config('services.oauth.instance_id');
    }

    /**
     * Get the placeholder for an encrypted credential field.
     */
    private function getCredentialPlaceholder(string $settingKey, string $configKey): string
    {
        $value = app(SettingsService::class)->get($settingKey) ?? config($configKey);

        return ! empty($value) ? 'Configured (leave empty to keep)' : 'Not configured';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Shipment Import')
                        ->description('Choose which tenant-managed import source is used by default. Batch size and log channel remain deployment-level config.')
                        ->schema([
                            Select::make('import_source')
                                ->label('Default Import Source')
                                ->options([
                                    'database' => 'Database',
                                    'shopify' => 'Shopify',
                                    'amazon' => 'Amazon',
                                ])
                                ->default('database')
                                ->required(),
                        ])
                        ->columns(1),

                    Section::make('Company Information')
                        ->description('General company settings')
                        ->schema([
                            TextInput::make('company_name')
                                ->label('Company Name')
                                ->maxLength(255),
                        ])
                        ->columns(1),

                    Section::make('Ship-From Address')
                        ->description('Managed in Settings > Locations. The default location is used as the return address on labels.')
                        ->schema([
                            Placeholder::make('default_location')
                                ->label('Default Location')
                                ->content(fn () => Location::getDefault()?->name ?? 'No default location set'),
                        ]),

                    Section::make('Features')
                        ->description('Enable or disable application features')
                        ->schema([
                            Toggle::make('packing_validation_enabled')
                                ->label('Packing Validation')
                                ->helperText('When enabled, all items must be scanned before shipping. When disabled, only weight and dimensions are required.')
                                ->default(true),
                            Toggle::make('transparency_enabled')
                                ->label('Transparency Program')
                                ->helperText('When enabled, shipment items requiring transparency codes will prompt for code scanning during packing.')
                                ->default(true),
                            Toggle::make('batch_shipping_enabled')
                                ->label('Batch Shipping')
                                ->helperText('When enabled, admins can select multiple shipments and generate labels in bulk.')
                                ->default(true),
                            Toggle::make('manual_shipping_enabled')
                                ->label('Manual Shipping')
                                ->helperText('When enabled, the Manual Ship page is available for creating ad-hoc shipments.')
                                ->default(true),
                        ])
                        ->columns(1),

                    Section::make('Carrier API')
                        ->description('Settings for carrier API requests')
                        ->schema([
                            TextInput::make('carrier_api_timeout')
                                ->label('Request Timeout (seconds)')
                                ->helperText('Maximum time to wait for a response from carrier APIs (USPS, FedEx, UPS). Default is 15 seconds.')
                                ->numeric()
                                ->minValue(5)
                                ->maxValue(60)
                                ->default(15)
                                ->suffix('seconds'),
                        ])
                        ->columns(1),

                    Section::make('Password Policy')
                        ->description('Password requirements for local accounts')
                        ->schema([
                            TextInput::make('password_min_length')
                                ->label('Minimum Length')
                                ->numeric()
                                ->minValue(8)
                                ->maxValue(128)
                                ->default(8)
                                ->suffix('characters'),
                            Toggle::make('password_require_mixed_case')
                                ->label('Require Mixed Case')
                                ->helperText('Require at least one uppercase and one lowercase letter.')
                                ->default(true),
                            Toggle::make('password_require_numbers')
                                ->label('Require Numbers')
                                ->helperText('Require at least one numeric character.')
                                ->default(true),
                            Toggle::make('password_require_symbols')
                                ->label('Require Symbols')
                                ->helperText('Require at least one special character.')
                                ->default(false),
                            TextInput::make('password_expiration_days')
                                ->label('Password Expiration')
                                ->helperText('Force users to change passwords after this many days. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(0)
                                ->suffix('days'),
                        ])
                        ->columns(2),

                    Section::make('Single Sign-On')
                        ->description('Allow users to sign in with external identity providers')
                        ->schema([
                            Toggle::make('google_sso_enabled')
                                ->label('Google SSO')
                                ->helperText('Show "Sign in with Google" button on the login page. Requires Google OAuth credentials in .env.')
                                ->default(false),
                        ])
                        ->columns(1),

                    Section::make('Data Retention')
                        ->description('Configure how long data is kept before automatic cleanup')
                        ->schema([
                            TextInput::make('audit_log_retention_days')
                                ->label('Audit Log Retention')
                                ->helperText('Audit log entries older than this will be automatically purged daily. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(365)
                                ->suffix('days'),
                            TextInput::make('rate_quote_retention_days')
                                ->label('Rate Quote Retention')
                                ->helperText('Rate quotes older than this will be automatically purged daily. The selected rate is always preserved on the package. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(60)
                                ->suffix('days'),
                            TextInput::make('pii_retention_days')
                                ->label('PII Retention (default)')
                                ->helperText('Days to keep recipient PII (name, address, phone, email) after shipping. Per-channel overrides can be set on each channel. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(90)
                                ->suffix('days'),
                            Toggle::make('archiving_enabled')
                                ->label('Shipment Archiving')
                                ->helperText('When enabled, fully-shipped shipments older than the retention period are exported to CSV and removed from the database weekly. Historical stats are preserved.')
                                ->default(false)
                                ->live(),
                            TextInput::make('archive_retention_days')
                                ->label('Archive After')
                                ->helperText('Shipped shipments older than this will be archived. Archives are saved to storage/app/archives/.')
                                ->numeric()
                                ->minValue(90)
                                ->maxValue(3650)
                                ->default(365)
                                ->suffix('days')
                                ->visible(fn (Get $get): bool => (bool) $get('archiving_enabled')),
                        ])
                        ->columns(2),

                    Section::make('USPS Credentials')
                        ->description('API credentials for USPS shipping services')
                        ->schema([
                            Placeholder::make('usps_oauth_status')
                                ->label('OAuth Status')
                                ->content(fn () => $this->renderOauthStatus(
                                    provider: 'usps',
                                    connectedAt: app(SettingsService::class)->get('usps.oauth_connected_at'),
                                ))
                                ->columnSpanFull(),
                            Placeholder::make('usps_sandbox_warning')
                                ->label('')
                                ->content(new HtmlString('<div class="text-warning-600 dark:text-warning-400 text-sm font-medium">⚠ Sandbox mode is on. USPS OAuth tokens are issued by the production COP Navigator and cannot be used in sandbox mode — USPS rates and labels will be unavailable until sandbox mode is disabled.</div>'))
                                ->columnSpanFull()
                                ->visible(fn () => app(SettingsService::class)->get('sandbox_mode', false) && app(OAuthService::class)->isConnected('usps')),
                            TextInput::make('usps_client_id')
                                ->label('Client ID')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('usps.client_id', 'services.usps.client_id')),
                            TextInput::make('usps_client_secret')
                                ->label('Client Secret')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('usps.client_secret', 'services.usps.client_secret')),
                            TextInput::make('usps_crid')
                                ->label('CRID')
                                ->maxLength(50),
                            TextInput::make('usps_mid')
                                ->label('MID')
                                ->maxLength(50),
                            Placeholder::make('usps_pricing_tier')
                                ->label('Pricing Tier')
                                ->content(fn () => match (Cache::get('usps_pricing_type')) {
                                    'CONTRACT' => new HtmlString('<span class="text-success-600 dark:text-success-400 font-medium">CONTRACT</span> — negotiated rates'),
                                    'RETAIL' => new HtmlString('<span class="text-warning-600 dark:text-warning-400 font-medium">RETAIL</span> — standard rates (no EPS contract)'),
                                    default => new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not tested yet</span>'),
                                })
                                ->dehydrated(false)
                                ->columnSpanFull(),
                        ])
                        ->footerActions([
                            Action::make('usps_connect')
                                ->label(fn () => app(OAuthService::class)->isConnected('usps') ? 'Reconnect' : 'Connect with OAuth')
                                ->icon('heroicon-o-link')
                                ->color(fn () => app(OAuthService::class)->isConnected('usps') ? 'warning' : 'primary')
                                ->disabled(fn () => ! $this->isBrokerConfigured())
                                ->tooltip(fn () => ! $this->isBrokerConfigured() ? 'OAuth broker not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID in .env.' : null)
                                ->requiresConfirmation()
                                ->modalHeading(fn () => app(OAuthService::class)->isConnected('usps') ? 'Reconnect USPS' : 'Connect USPS')
                                ->modalDescription(fn () => app(OAuthService::class)->isConnected('usps')
                                    ? 'This will replace the existing OAuth token with a new one. You will be redirected to USPS to re-authorize.'
                                    : 'You will be redirected to USPS to authorize access.')
                                ->action(function () {
                                    $url = app(OAuthService::class)->initiateAuthorization('usps');
                                    $this->redirect($url, navigate: false);
                                }),
                            Action::make('usps_disconnect')
                                ->label('Disconnect')
                                ->icon('heroicon-o-x-mark')
                                ->color('danger')
                                ->visible(fn () => app(OAuthService::class)->isConnected('usps'))
                                ->requiresConfirmation()
                                ->modalHeading('Disconnect USPS OAuth')
                                ->modalDescription('This will remove the OAuth access token. You can reconnect anytime, or the app will fall back to client credentials if configured.')
                                ->action(function () {
                                    app(OAuthService::class)->disconnect('usps');
                                    Notification::make()->success()->title('USPS disconnected.')->send();
                                }),
                            Action::make('test_usps_connection')
                                ->label('Test Connection')
                                ->icon('heroicon-o-signal')
                                ->action(fn () => $this->testUspsConnection()),
                        ])
                        ->columns(2)
                        ->collapsed(),

                    Section::make(new HtmlString('<span class="flex items-center gap-2"><img src="'.Carrier::logoUrlForName('FedEx').'" alt="FedEx" class="h-8 inline-block">FedEx Credentials</span>'))
                        ->description('API credentials for FedEx shipping services')
                        ->schema([
                            Placeholder::make('fedex_account_status')
                                ->label('Account Status')
                                ->content(fn () => $this->renderFedexAccountStatus())
                                ->columnSpanFull(),
                            TextInput::make('fedex_api_key')
                                ->label('Production API Key')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('fedex.api_key', 'services.fedex.api_key'))
                                ->visible(fn () => ! $this->isBrokerConfigured()),
                            TextInput::make('fedex_api_secret')
                                ->label('Production API Secret')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('fedex.api_secret', 'services.fedex.api_secret'))
                                ->visible(fn () => ! $this->isBrokerConfigured()),
                            TextInput::make('fedex_sandbox_api_key')
                                ->label('Sandbox API Key')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('fedex.sandbox_api_key', 'services.fedex.sandbox_api_key'))
                                ->visible(fn () => ! $this->isBrokerConfigured()),
                            TextInput::make('fedex_sandbox_api_secret')
                                ->label('Sandbox API Secret')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('fedex.sandbox_api_secret', 'services.fedex.sandbox_api_secret'))
                                ->visible(fn () => ! $this->isBrokerConfigured()),
                            TextInput::make('fedex_account_number')
                                ->label('Account Number')
                                ->maxLength(50)
                                ->copyable()
                                ->readOnly(fn () => $this->isFedexAccountConnected()),
                            Html::make(fn () => new HtmlString(view('components.legal-disclaimers', ['show' => ['fedex']])->render()))
                                ->columnSpanFull(),
                        ])
                        ->footerActions([
                            Action::make('fedex_register')
                                ->label(fn () => $this->isFedexAccountConnected() ? 'Reconnect FedEx Account' : 'Connect FedEx Account')
                                ->icon('heroicon-o-link')
                                ->color(fn () => $this->isFedexAccountConnected() ? 'warning' : 'primary')
                                ->modalHeading(fn () => new HtmlString(
                                    '<span class="flex items-center gap-2"><img src="'.Carrier::logoUrlForName('FedEx').'" alt="FedEx" class="h-5 inline-block">'
                                        .($this->isFedexAccountConnected() ? 'Reconnect FedEx Account' : 'Connect FedEx Account')
                                        .'</span>'
                                ))
                                ->modalWidth('7xl')
                                ->extraModalWindowAttributes(['style' => 'max-width: 96rem;'])
                                ->closeModalByClickingAway(false)
                                ->closeModalByEscaping(false)
                                ->modalSubmitAction(false)
                                ->mountUsing(function (?Schema $schema): void {
                                    $this->resetFedexRegistrationState();
                                    $schema?->fill();
                                })
                                ->modifyWizardUsing(fn (Wizard $wizard) => $wizard
                                    ->submitAction($this->renderFedexWizardSubmitAction())
                                    ->nextAction(fn (Action $action) => $action->disabled(! $this->fedexEulaAccepted))
                                    ->previousAction(
                                        fn (Action $action) => $action
                                            ->hidden($this->fedexSupportFallbackActive && ! $this->hasAvailableFedexFactor2Methods())
                                            ->disabled($this->fedexSupportFallbackActive && ! $this->hasAvailableFedexFactor2Methods())
                                    ))
                                ->steps([
                                    Step::make('Terms of Service')
                                        ->description('Review and accept the FedEx EULA')
                                        ->schema([
                                            Html::make(fn () => new HtmlString(
                                                view('filament.pages.settings.fedex-eula')->render()
                                            ))->columnSpanFull(),
                                            Hidden::make('eula_accepted')
                                                ->default(false),
                                        ])
                                        ->afterValidation(function () {
                                            if (! $this->fedexEulaAccepted) {
                                                $this->addError('fedexEulaAccepted', 'You must scroll to the bottom and accept the FedEx EULA to continue.');

                                                throw new Halt;
                                            }
                                        }),

                                    Step::make('Account Verification')
                                        ->description('Enter your FedEx account number and address')
                                        ->schema([
                                            TextInput::make('fedex_reg_account_number')
                                                ->label('FedEx Account Number')
                                                ->required()
                                                ->length(9)
                                                ->numeric(),
                                            TextInput::make('fedex_reg_customer_name')
                                                ->label('Company / Customer Name')
                                                ->required()
                                                ->maxLength(50)
                                                ->columnSpanFull()
                                                ->helperText('Must match the name on your FedEx account.'),
                                            Toggle::make('fedex_reg_residential')
                                                ->label('Residential Address')
                                                ->default(false)
                                                ->columnSpanFull(),
                                            AddressForm::countrySelect('fedex_reg_country', 'fedex_reg_state')
                                                ->label('Country')
                                                ->columnSpanFull(),
                                            TextInput::make('fedex_reg_street1')
                                                ->label('Street Address')
                                                ->required()
                                                ->maxLength(35)
                                                ->columnSpanFull(),
                                            TextInput::make('fedex_reg_street2')
                                                ->label('Street Address Line 2')
                                                ->maxLength(35)
                                                ->columnSpanFull(),
                                            TextInput::make('fedex_reg_city')
                                                ->label('City')
                                                ->required()
                                                ->maxLength(35),
                                            Select::make('fedex_reg_state')
                                                ->label(fn (Get $get): string => app(AddressReferenceService::class)->getAdministrativeAreaLabel($get('fedex_reg_country')))
                                                ->options(fn (Get $get): array => app(AddressReferenceService::class)->getSubdivisionOptions($get('fedex_reg_country')))
                                                ->native(false)
                                                ->searchable()
                                                ->optionsLimit(300)
                                                ->required(fn (Get $get): bool => app(AddressReferenceService::class)->isAdministrativeAreaRequired($get('fedex_reg_country')))
                                                ->hidden(fn (Get $get): bool => app(AddressReferenceService::class)->getSubdivisionOptions($get('fedex_reg_country')) === [])
                                                ->live(),
                                            TextInput::make('fedex_reg_postal_code')
                                                ->label('ZIP / Postal Code')
                                                ->required()
                                                ->maxLength(10),
                                        ])
                                        ->columns(3)
                                        ->afterValidation(function (Get $get) {
                                            try {
                                                $result = app(FedexRegistrationService::class)->validateAddress(
                                                    accountNumber: $get('fedex_reg_account_number'),
                                                    customerName: $get('fedex_reg_customer_name'),
                                                    residential: (bool) ($get('fedex_reg_residential') ?? false),
                                                    street1: $get('fedex_reg_street1'),
                                                    street2: $get('fedex_reg_street2') ?? '',
                                                    city: $get('fedex_reg_city'),
                                                    stateOrProvinceCode: $get('fedex_reg_state') ?? '',
                                                    postalCode: $get('fedex_reg_postal_code'),
                                                    countryCode: $get('fedex_reg_country'),
                                                );
                                            } catch (\Throwable $e) {
                                                Notification::make()->danger()->title('FedEx Error')->body($e->getMessage())->send();

                                                throw new Halt;
                                            }

                                            // MFA bypassed — credentials returned immediately
                                            if (! $result['mfaRequired']) {
                                                try {
                                                    $this->completeFedexRegistration(
                                                        accountNumber: $get('fedex_reg_account_number'),
                                                        childKey: $result['credentials']['child_Key'],
                                                        childSecret: $result['credentials']['child_secret'],
                                                    );
                                                    Notification::make()->success()->title('FedEx Account added Successfully.')->send();
                                                    $this->redirect(static::getUrl());

                                                    throw new Halt;
                                                } catch (Halt $exception) {
                                                    throw $exception;
                                                } catch (\Throwable $e) {
                                                    $this->notifyFedexRegistrationError($e);

                                                    throw new Halt;
                                                }
                                            }

                                            $this->storeFedexVerificationState($result);
                                        }),

                                    Step::make('Verification Method')
                                        ->description('Choose how to verify your identity')
                                        ->schema(fn () => [
                                            Radio::make('fedex_factor2_method')
                                                ->label('Verification Method')
                                                ->options($this->getFedexAvailableVerificationOptions())
                                                ->required()
                                                ->live()
                                                ->columnSpanFull(),
                                        ])
                                        ->afterValidation(function (Get $get) {
                                            $this->fedexFactor2Method = $get('fedex_factor2_method');
                                            $this->fedexSupportFallbackActive = false;
                                            $this->refreshMountedFedexAction();

                                            if ($this->fedexFactor2Method !== 'INVOICE') {
                                                try {
                                                    app(FedexRegistrationService::class)->sendPin(
                                                        $this->fedexAccountAuthToken,
                                                        $this->fedexFactor2Method,
                                                    );
                                                } catch (FedexRegistrationMaxRetriesException $e) {
                                                    $this->handleFedexRegistrationLockout($e);
                                                } catch (\Throwable $e) {
                                                    $this->notifyFedexRegistrationError($e);

                                                    throw new Halt;
                                                }
                                            }
                                        }),

                                    Step::make('Enter Verification')
                                        ->description(fn () => $this->fedexSupportFallbackActive
                                            ? 'Contact customer service'
                                            : ($this->fedexFactor2Method === 'INVOICE' ? 'Enter a recent FedEx invoice' : 'Enter the PIN sent to you'))
                                        ->schema(function () {
                                            if ($this->fedexSupportFallbackActive) {
                                                $body = $this->hasAvailableFedexFactor2Methods()
                                                    ? 'We are unable to process this request. Please try again later or call FedEx Customer Service and ask for technical support. You may also go back and choose a different validation method.'
                                                    : 'We are unable to process this request. Please try again later or call FedEx Customer Service and ask for technical support.';

                                                return [
                                                    Placeholder::make('fedex_support_fallback')
                                                        ->hiddenLabel()
                                                        ->content(new HtmlString(
                                                            '<div>'.
                                                            '<div class="rounded-lg border border-danger-200 bg-danger-50 p-4 text-sm font-medium text-danger-700 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-300">'.
                                                            $body.
                                                            '</div>'.
                                                            '</div>'
                                                        ))
                                                        ->columnSpanFull(),
                                                ];
                                            }

                                            if ($this->fedexFactor2Method === 'INVOICE') {
                                                return [
                                                    TextInput::make('fedex_invoice_number')
                                                        ->label('Invoice Number')
                                                        ->required()
                                                        ->integer()
                                                        ->maxLength(9),
                                                    DatePicker::make('fedex_invoice_date')
                                                        ->label('Invoice Date')
                                                        ->required()
                                                        ->maxDate(now())
                                                        ->minDate(now()->subDays(90))
                                                        ->helperText('Invoice must be within the last 90 days.'),
                                                    TextInput::make('fedex_invoice_amount')
                                                        ->label('Invoice Amount')
                                                        ->required()
                                                        ->numeric()
                                                        ->minValue(0),
                                                    Select::make('fedex_invoice_currency')
                                                        ->label('Currency')
                                                        ->options(['USD' => 'USD', 'CAD' => 'CAD', 'EUR' => 'EUR', 'GBP' => 'GBP'])
                                                        ->default('USD')
                                                        ->required(),
                                                ];
                                            }

                                            return [
                                                TextInput::make('fedex_pin')
                                                    ->label('6-Digit PIN')
                                                    ->required()
                                                    ->length(6)
                                                    ->numeric()
                                                    ->columnSpanFull(),
                                                Html::make(fn () => new HtmlString(
                                                    '<button type="button" wire:click="resendFedexPin" class="text-sm text-primary-600 hover:underline dark:text-primary-400">Resend PIN</button>'
                                                ))->columnSpanFull(),
                                            ];
                                        })
                                        ->columns(2),
                                ])
                                ->action(function (array $data) {
                                    if ($this->fedexSupportFallbackActive) {
                                        return;
                                    }

                                    try {
                                        if ($this->fedexFactor2Method === 'INVOICE') {
                                            $credentials = app(FedexRegistrationService::class)->verifyInvoice(
                                                accountAuthToken: $this->fedexAccountAuthToken,
                                                invoiceNumber: (int) $data['fedex_invoice_number'],
                                                invoiceDate: $data['fedex_invoice_date'],
                                                invoiceAmount: (float) $data['fedex_invoice_amount'],
                                                invoiceCurrency: $data['fedex_invoice_currency'],
                                            );
                                        } else {
                                            $credentials = app(FedexRegistrationService::class)->verifyPin(
                                                accountAuthToken: $this->fedexAccountAuthToken,
                                                pin: $data['fedex_pin'],
                                            );
                                        }

                                        $this->completeFedexRegistration(
                                            accountNumber: $data['fedex_reg_account_number'],
                                            childKey: $credentials['child_Key'],
                                            childSecret: $credentials['child_secret'],
                                        );
                                    } catch (FedexRegistrationMaxRetriesException $e) {
                                        $this->handleFedexRegistrationLockout($e);

                                        throw new Halt;
                                    } catch (\Throwable $e) {
                                        $this->notifyFedexRegistrationError($e);

                                        throw new Halt;
                                    }

                                    Notification::make()->success()->title('FedEx Account added Successfully.')->send();
                                    $this->redirect(static::getUrl());
                                }),
                            Action::make('fedex_disconnect')
                                ->label('Disconnect')
                                ->icon('heroicon-o-x-mark')
                                ->color('danger')
                                ->visible(fn () => $this->isFedexAccountConnected())
                                ->requiresConfirmation()
                                ->modalHeading('Disconnect FedEx Account')
                                ->modalDescription('This will remove your FedEx credentials. You can reconnect your account anytime.')
                                ->action(function () {
                                    app(FedexRegistrationService::class)->removeChildCredentials();
                                    Notification::make()->success()->title('FedEx account disconnected.')->send();
                                }),
                        ])
                        ->columns(2)
                        ->collapsed(),

                    Section::make('UPS Credentials')
                        ->description('Connect your UPS account via OAuth for rates and label generation.')
                        ->schema([
                            Placeholder::make('ups_oauth_status')
                                ->label('OAuth Status')
                                ->content(fn () => $this->renderOauthStatus(
                                    provider: 'ups',
                                    connectedAt: app(SettingsService::class)->get('ups.oauth_connected_at'),
                                )),
                            TextInput::make('ups_account_number')
                                ->label('Account Number')
                                ->maxLength(50),

                            ...$this->upsCredentialFields(),
                        ])
                        ->footerActions([
                            Action::make('ups_connect')
                                ->label(fn () => app(OAuthService::class)->isConnected('ups') ? 'Reconnect' : 'Connect with OAuth')
                                ->icon('heroicon-o-link')
                                ->color(fn () => app(OAuthService::class)->isConnected('ups') ? 'warning' : 'primary')
                                ->disabled(fn () => ! $this->isBrokerConfigured())
                                ->tooltip(fn () => ! $this->isBrokerConfigured() ? 'OAuth broker not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID in .env.' : null)
                                ->requiresConfirmation()
                                ->modalHeading(fn () => app(OAuthService::class)->isConnected('ups') ? 'Reconnect UPS' : 'Connect UPS')
                                ->modalDescription(fn () => app(OAuthService::class)->isConnected('ups')
                                    ? 'This will replace the existing OAuth token with a new one. You will be redirected to UPS to re-authorize.'
                                    : 'You will be redirected to UPS to authorize access.')
                                ->action(function () {
                                    $url = app(OAuthService::class)->initiateAuthorization('ups');
                                    $this->redirect($url, navigate: false);
                                }),
                            Action::make('ups_disconnect')
                                ->label('Disconnect')
                                ->icon('heroicon-o-x-mark')
                                ->color('danger')
                                ->visible(fn () => app(OAuthService::class)->isConnected('ups'))
                                ->requiresConfirmation()
                                ->modalHeading('Disconnect UPS OAuth')
                                ->modalDescription('This will remove the OAuth access token. You can reconnect anytime, or the app will fall back to client credentials if configured.')
                                ->action(function () {
                                    app(OAuthService::class)->disconnect('ups');
                                    Notification::make()->success()->title('UPS disconnected.')->send();
                                }),
                        ])
                        ->columns(2)
                        ->collapsed(),

                    Section::make('Shopify Integration')
                        ->description('Connect your Shopify store via OAuth to import orders and export fulfillments.')
                        ->schema([
                            Placeholder::make('shopify_oauth_status')
                                ->label('OAuth Status')
                                ->content(fn () => $this->renderOauthStatus(
                                    provider: 'shopify',
                                    connectedAt: app(SettingsService::class)->get('shopify.oauth_connected_at'),
                                    scopes: app(SettingsService::class)->get('shopify.oauth_scopes'),
                                )),
                            TextInput::make('shopify_shop_domain')
                                ->label('Shop Domain')
                                ->placeholder('mystore.myshopify.com')
                                ->maxLength(255),
                            TextInput::make('shopify_api_version')
                                ->label('API Version')
                                ->placeholder('2025-01')
                                ->maxLength(20),
                            Toggle::make('shopify_import_enabled')
                                ->label('Enable Shopify Import'),
                            Toggle::make('shopify_export_enabled')
                                ->label('Enable Shopify Export'),
                            TextInput::make('shopify_channel_name')
                                ->label('Channel Name')
                                ->placeholder('Shopify')
                                ->maxLength(255),
                            Select::make('shopify_shipping_method')
                                ->label('Default Shipping Method')
                                ->options(fn () => ShippingMethod::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->placeholder('None'),
                            Toggle::make('shopify_notify_customer')
                                ->label('Notify Customer on Export'),

                            ...$this->shopifyCredentialFields(),
                        ])
                        ->footerActions([
                            Action::make('shopify_connect')
                                ->label(fn () => app(OAuthService::class)->isConnected('shopify') ? 'Reconnect' : 'Connect with OAuth')
                                ->icon('heroicon-o-link')
                                ->color(fn () => app(OAuthService::class)->isConnected('shopify') ? 'warning' : 'primary')
                                ->disabled(fn () => ! $this->isBrokerConfigured())
                                ->tooltip(fn () => ! $this->isBrokerConfigured() ? 'OAuth broker not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID in .env.' : null)
                                ->requiresConfirmation()
                                ->modalHeading(fn () => app(OAuthService::class)->isConnected('shopify') ? 'Reconnect Shopify' : 'Connect Shopify')
                                ->modalDescription(fn () => app(OAuthService::class)->isConnected('shopify')
                                    ? 'This will replace the existing OAuth token with a new one. You will be redirected to Shopify to re-authorize.'
                                    : 'You will be redirected to Shopify to authorize access. Make sure Shop Domain is saved first.')
                                ->action(function () {
                                    $url = app(OAuthService::class)->initiateAuthorization('shopify');
                                    $this->redirect($url, navigate: false);
                                }),
                            Action::make('shopify_disconnect')
                                ->label('Disconnect')
                                ->icon('heroicon-o-x-mark')
                                ->color('danger')
                                ->visible(fn () => app(OAuthService::class)->isConnected('shopify'))
                                ->requiresConfirmation()
                                ->modalHeading('Disconnect Shopify OAuth')
                                ->modalDescription('This will remove the OAuth access token. You can reconnect anytime, or the app will fall back to client credentials if configured.')
                                ->action(function () {
                                    app(OAuthService::class)->disconnect('shopify');
                                    Notification::make()->success()->title('Shopify disconnected.')->send();
                                }),
                        ])
                        ->columns(2)
                        ->collapsed(),

                    Section::make('Amazon Integration')
                        ->description('Credentials for importing orders from Amazon SP-API')
                        ->schema([
                            TextInput::make('amazon_client_id')
                                ->label('Client ID')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('amazon.client_id', 'services.amazon.client_id')),
                            TextInput::make('amazon_client_secret')
                                ->label('Client Secret')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('amazon.client_secret', 'services.amazon.client_secret')),
                            TextInput::make('amazon_refresh_token')
                                ->label('Refresh Token')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('amazon.refresh_token', 'services.amazon.refresh_token')),
                            TextInput::make('amazon_marketplace_id')
                                ->label('Marketplace ID')
                                ->placeholder('ATVPDKIKX0DER')
                                ->maxLength(50),
                            Toggle::make('amazon_import_enabled')
                                ->label('Enable Amazon Import'),
                            Toggle::make('amazon_export_enabled')
                                ->label('Enable Amazon Export'),
                            TextInput::make('amazon_channel_name')
                                ->label('Channel Name')
                                ->placeholder('Amazon')
                                ->maxLength(255),
                            Select::make('amazon_shipping_method')
                                ->label('Default Shipping Method')
                                ->options(fn () => ShippingMethod::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->placeholder('None'),
                            TextInput::make('amazon_lookback_days')
                                ->label('Lookback Days')
                                ->numeric()
                                ->default(30)
                                ->minValue(1),
                        ])
                        ->columns(2)
                        ->collapsed(),

                    Section::make('Database Import')
                        ->description('Configure the external database connection and optional SQL queries for importing shipments and exporting tracking updates.')
                        ->schema([
                            Select::make('import_db_driver')
                                ->label('Driver')
                                ->options([
                                    'mysql' => 'MySQL / MariaDB',
                                    'pgsql' => 'PostgreSQL',
                                    'sqlsrv' => 'SQL Server',
                                    'sqlite' => 'SQLite',
                                ])
                                ->default('mysql'),
                            TextInput::make('import_db_host')
                                ->label('Host'),
                            TextInput::make('import_db_port')
                                ->label('Port'),
                            TextInput::make('import_db_database')
                                ->label('Database'),
                            TextInput::make('import_db_username')
                                ->label('Username'),
                            TextInput::make('import_db_password')
                                ->label('Password')
                                ->password()
                                ->revealable()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('import.db_password', 'database.connections.import.password')),
                            Textarea::make('import_shipments_query')
                                ->label('Shipments Query')
                                ->helperText('Optional custom SQL to fetch shipments. Leave blank to query the configured shipments table.')
                                ->rows(4)
                                ->columnSpanFull(),
                            Textarea::make('import_shipment_items_query')
                                ->label('Shipment Items Query')
                                ->helperText('Optional custom SQL to fetch shipment items. Use :shipment_reference as the placeholder value.')
                                ->rows(4)
                                ->columnSpanFull(),
                            Textarea::make('import_export_query')
                                ->label('Export Query')
                                ->helperText('Optional SQL used to export tracking data back to the external database. Leave blank to disable database export queries.')
                                ->rows(4)
                                ->columnSpanFull(),
                            Toggle::make('import_ssh_enabled')
                                ->label('Connect via SSH Tunnel')
                                ->columnSpanFull()
                                ->live(),
                            TextInput::make('import_ssh_host')
                                ->label('SSH Host')
                                ->visible(fn (Get $get) => (bool) $get('import_ssh_enabled')),
                            TextInput::make('import_ssh_port')
                                ->label('SSH Port')
                                ->default('22')
                                ->visible(fn (Get $get) => (bool) $get('import_ssh_enabled')),
                            TextInput::make('import_ssh_user')
                                ->label('SSH User')
                                ->visible(fn (Get $get) => (bool) $get('import_ssh_enabled')),
                            TextInput::make('import_ssh_remote_host')
                                ->label('Remote Host')
                                ->helperText('DB host as seen from the SSH server. Leave blank to use the DB host above.')
                                ->visible(fn (Get $get) => (bool) $get('import_ssh_enabled')),
                            TextInput::make('import_ssh_remote_port')
                                ->label('Remote Port')
                                ->helperText('DB port as seen from the SSH server. Leave blank to use the DB port above.')
                                ->visible(fn (Get $get) => (bool) $get('import_ssh_enabled')),
                            Textarea::make('import_ssh_host_key')
                                ->label('SSH Server Host Key')
                                ->helperText('Paste the SSH server host key so PolyBag can verify it is connecting to the correct server. Example: bastion.example.com ssh-ed25519 AAAA...')
                                ->visible(fn (Get $get) => (bool) $get('import_ssh_enabled'))
                                ->required(fn (Get $get) => (bool) $get('import_ssh_enabled'))
                                ->rows(3)
                                ->columnSpanFull(),
                            TextInput::make('ssh_public_key')
                                ->label('SSH Public Key')
                                ->helperText('Add this to ~/.ssh/authorized_keys on the SSH host. This allows PolyBag to log in. Add permitopen="host:port" to restrict forwarding.')
                                ->visible(fn (Get $get) => (bool) $get('import_ssh_enabled'))
                                ->columnSpanFull()
                                ->readOnly()
                                ->copyable()
                                ->dehydrated(false),
                        ])
                        ->columns(2)
                        ->collapsed()
                        ->footerActions([
                            Action::make('test_import_connection')
                                ->label('Test Connection')
                                ->icon('heroicon-o-signal')
                                ->action(function () {
                                    $this->testImportConnection();
                                }),
                        ]),

                    Section::make('Testing')
                        ->description('Sandbox and testing settings')
                        ->schema([
                            Toggle::make('sandbox_mode')
                                ->label('Sandbox Mode')
                                ->helperText('When enabled, USPS, FedEx, and UPS API calls use sandbox/test URLs instead of production.')
                                ->default(false)
                                ->live(),
                            Toggle::make('suppress_printing')
                                ->label('Suppress Printing')
                                ->helperText('When enabled, label printing is skipped after shipping. Only available in sandbox mode.')
                                ->default(false)
                                ->visible(fn (Get $get): bool => (bool) $get('sandbox_mode')),
                        ])
                        ->columns(1),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save Settings')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $sandboxMode = (bool) ($data['sandbox_mode'] ?? false);
        $suppressPrinting = $sandboxMode ? (bool) ($data['suppress_printing'] ?? false) : false;
        $previousSandboxMode = (bool) app(SettingsService::class)->get('sandbox_mode', false);

        // Map form fields to setting keys
        $settings = [
            'company_name' => $data['company_name'] ?? '',
            'packing_validation_enabled' => $data['packing_validation_enabled'] ?? true,
            'transparency_enabled' => $data['transparency_enabled'] ?? true,
            'batch_shipping_enabled' => $data['batch_shipping_enabled'] ?? true,
            'manual_shipping_enabled' => $data['manual_shipping_enabled'] ?? true,
            'carrier_api_timeout' => (int) ($data['carrier_api_timeout'] ?? 15),
            'import_source' => $data['import_source'] ?? 'database',
            'audit_log_retention_days' => (int) ($data['audit_log_retention_days'] ?? 365),
            'rate_quote_retention_days' => (int) ($data['rate_quote_retention_days'] ?? 60),
            'pii_retention_days' => (int) ($data['pii_retention_days'] ?? 90),
            'archiving_enabled' => (bool) ($data['archiving_enabled'] ?? false),
            'archive_retention_days' => (int) ($data['archive_retention_days'] ?? 365),
            'password_min_length' => (int) ($data['password_min_length'] ?? 8),
            'password_require_mixed_case' => (bool) ($data['password_require_mixed_case'] ?? true),
            'password_require_numbers' => (bool) ($data['password_require_numbers'] ?? true),
            'password_require_symbols' => (bool) ($data['password_require_symbols'] ?? false),
            'password_expiration_days' => (int) ($data['password_expiration_days'] ?? 0),
            'google_sso_enabled' => (bool) ($data['google_sso_enabled'] ?? false),
            'sandbox_mode' => $sandboxMode,
            'suppress_printing' => $suppressPrinting,
            'shopify.import_enabled' => (bool) ($data['shopify_import_enabled'] ?? false),
            'shopify.export_enabled' => (bool) ($data['shopify_export_enabled'] ?? false),
            'shopify.channel_name' => $data['shopify_channel_name'] ?? 'Shopify',
            'shopify.shipping_method' => blank($data['shopify_shipping_method'] ?? null) ? null : (string) $data['shopify_shipping_method'],
            'shopify.notify_customer' => (bool) ($data['shopify_notify_customer'] ?? false),
            'amazon.import_enabled' => (bool) ($data['amazon_import_enabled'] ?? false),
            'amazon.export_enabled' => (bool) ($data['amazon_export_enabled'] ?? false),
            'amazon.channel_name' => $data['amazon_channel_name'] ?? 'Amazon',
            'amazon.shipping_method' => blank($data['amazon_shipping_method'] ?? null) ? null : (string) $data['amazon_shipping_method'],
            'amazon.lookback_days' => (int) ($data['amazon_lookback_days'] ?? 30),
            'import.shipments_query' => blank($data['import_shipments_query'] ?? null) ? null : trim((string) $data['import_shipments_query']),
            'import.shipment_items_query' => blank($data['import_shipment_items_query'] ?? null) ? null : trim((string) $data['import_shipment_items_query']),
            'import.export_query' => blank($data['import_export_query'] ?? null) ? null : trim((string) $data['import_export_query']),
        ];

        // Update each standard setting
        foreach ($settings as $key => $value) {
            $setting = Setting::find($key);

            if ($setting) {
                $setting->value = $value;
                $setting->save();
            } else {
                $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');
                $group = str_contains($key, '.') ? explode('.', $key)[0] : 'general';

                Setting::create([
                    'key' => $key,
                    'value' => is_bool($value) ? ($value ? '1' : '0') : $value,
                    'type' => $type,
                    'group' => $group,
                ]);
            }
        }

        $credentialsChanged = false;

        // Save encrypted credential fields (skip empty to preserve existing values)
        foreach (self::ENCRYPTED_FIELDS as $formField => $settingKey) {
            $value = $data[$formField] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }

            $group = explode('.', $settingKey)[0];
            app(SettingsService::class)->set($settingKey, $value, 'string', encrypted: true, group: $group);
            $credentialsChanged = true;
        }

        // Save non-encrypted credential fields
        foreach (self::CREDENTIAL_FIELDS as $formField => $settingKey) {
            $value = $data[$formField] ?? '';
            $group = explode('.', $settingKey)[0];
            app(SettingsService::class)->set($settingKey, $value, 'string', group: $group);
        }

        // Save import SSH enabled as boolean
        app(SettingsService::class)->set('import.ssh_enabled', (bool) ($data['import_ssh_enabled'] ?? false), 'boolean', group: 'import');
        $this->writeImportSshKnownHostsFile($data['import_ssh_host_key'] ?? '');

        app(SettingsService::class)->clearCache();

        // Clear cached OAuth tokens when sandbox mode or credentials change
        if ($sandboxMode !== $previousSandboxMode || $credentialsChanged) {
            Cache::forget('usps_authenticator');
            Cache::forget('usps_payment_authorization_token');
            Cache::forget('fedex_authenticator');
            Cache::forget('fedex_authenticator_sandbox');
            Cache::forget('ups_authenticator');
            Cache::forget('ups_oauth_token');
            Cache::forget('amazon_sp_api_access_token');
            Cache::forget('shopify_access_token');
        }

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }

    private function getSshPublicKey(): string
    {
        $pubKeyPath = storage_path('app/private/ssh/id_ed25519.pub');
        if (! file_exists($pubKeyPath)) {
            return 'SSH key not generated. Run: php artisan app:generate-ssh-key';
        }

        return 'no-pty,no-X11-forwarding,no-agent-forwarding '.trim(file_get_contents($pubKeyPath));
    }

    private function getImportSshKnownHostsPath(): string
    {
        return storage_path('app/private/ssh/import_known_hosts');
    }

    private function writeImportSshKnownHostsFile(?string $knownHostsEntry): void
    {
        $path = $this->getImportSshKnownHostsPath();
        $entry = trim((string) $knownHostsEntry);

        if ($entry === '') {
            if (file_exists($path)) {
                @unlink($path);
            }

            return;
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($path, $entry.PHP_EOL);
        chmod($path, 0600);
    }

    private function renderOauthStatus(string $provider, ?string $connectedAt = null, ?string $scopes = null): HtmlString
    {
        $oauthService = app(OAuthService::class);

        return new HtmlString(
            view('filament.pages.settings.oauth-status', [
                'connected' => $oauthService->isConnected($provider),
                'time' => $connectedAt ? Carbon::parse($connectedAt)->diffForHumans() : null,
                'scopes' => $scopes,
            ])->render()
        );
    }

    public function testUspsConnection(): void
    {
        $authMode = app(SettingsService::class)->get('usps.auth_mode', 'client_credentials');

        // Clear cached tokens so we test fresh from stored credentials
        Cache::forget('usps_authenticator');
        Cache::forget('usps_oauth_token');

        // For client credentials, verify we can obtain a token before testing rates
        if ($authMode !== 'authorization_code') {
            try {
                $connector = new USPSConnector;
                $connector->getAccessToken();
            } catch (\Throwable $e) {
                Notification::make()
                    ->danger()
                    ->title('USPS authentication failed')
                    ->body($e->getMessage())
                    ->send();

                return;
            }
        }

        // Test with the connector that will actually be used — auth mode aware
        try {
            $connector = USPSConnector::getAuthenticatedConnector();

            $request = new ShippingOptions;
            $request->body()->set([
                'pricingOptions' => [['priceType' => 'CONTRACT', 'paymentAccount' => ['accountType' => 'EPS', 'accountNumber' => app(SettingsService::class)->get('usps.eps_account', app(SettingsService::class)->get('usps.crid'))]]],
                'originZIPCode' => '90210',
                'destinationZIPCode' => '10001',
                'packageDescription' => ['weight' => 1.0, 'length' => 10, 'width' => 8, 'height' => 4, 'mailClass' => 'ALL_OUTBOUND', 'mailingDate' => date('Y-m-d')],
            ]);

            $connector->send($request);

            Cache::put('usps_pricing_type', 'CONTRACT', now()->addDays(7));

            Notification::make()
                ->success()
                ->title('USPS connected — CONTRACT pricing')
                ->body('Negotiated rates are available for this account.')
                ->send();
        } catch (ForbiddenException) {
            Cache::put('usps_pricing_type', 'RETAIL', now()->addDays(7));

            Notification::make()
                ->warning()
                ->title('USPS connected — RETAIL pricing')
                ->body('Authentication succeeded but this account does not have EPS contract access. Standard retail rates will be used. Contact USPS to enable negotiated rates.')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->warning()
                ->title('USPS authenticated — rate test inconclusive')
                ->body('Credentials are valid but the rate check failed: '.$e->getMessage())
                ->send();
        }
    }

    private function testImportConnection(): void
    {
        $data = $this->data;
        $connection = 'import';

        // Temporarily apply form values to the connection config
        config([
            "database.connections.{$connection}.driver" => $data['import_db_driver'] ?? 'mysql',
            "database.connections.{$connection}.host" => $data['import_db_host'] ?? '127.0.0.1',
            "database.connections.{$connection}.port" => $data['import_db_port'] ?? '3306',
            "database.connections.{$connection}.database" => $data['import_db_database'] ?? '',
            "database.connections.{$connection}.username" => $data['import_db_username'] ?? '',
            "database.connections.{$connection}.password" => ! empty($data['import_db_password'])
                ? $data['import_db_password']
                : (app(SettingsService::class)->get('import.db_password') ?? config("database.connections.{$connection}.password")),
        ]);

        DB::purge($connection);

        $sshConfig = null;
        $tunnel = null;

        try {
            // Open SSH tunnel if enabled
            if ($data['import_ssh_enabled'] ?? false) {
                $keyPath = storage_path('app/private/ssh/id_ed25519');
                if (! file_exists($keyPath)) {
                    throw new \RuntimeException('SSH key not found. Run `php artisan app:generate-ssh-key` first.');
                }

                $tunnel = SshTunnel::fromConfig([
                    'ssh_host' => $data['import_ssh_host'] ?? '',
                    'ssh_port' => (int) ($data['import_ssh_port'] ?? 22),
                    'ssh_user' => $data['import_ssh_user'] ?? '',
                    'ssh_key' => $keyPath,
                    'remote_host' => $data['import_ssh_remote_host'] ?: ($data['import_db_host'] ?? '127.0.0.1'),
                    'remote_port' => (int) ($data['import_ssh_remote_port'] ?: ($data['import_db_port'] ?? 3306)),
                    'known_hosts_entry' => $data['import_ssh_host_key'] ?? '',
                    'known_hosts_file' => $this->getImportSshKnownHostsPath(),
                ]);

                $localPort = $tunnel->open();
                config([
                    "database.connections.{$connection}.host" => '127.0.0.1',
                    "database.connections.{$connection}.port" => $localPort,
                ]);
                DB::purge($connection);
            }

            DB::connection($connection)->getPdo();

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
            DB::purge($connection);
        }
    }
}
