<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Setting;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
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
        'ups_client_id' => 'ups.client_id',
        'ups_client_secret' => 'ups.client_secret',
        'shopify_client_id' => 'shopify.client_id',
        'shopify_client_secret' => 'shopify.client_secret',
        'amazon_client_id' => 'amazon.client_id',
        'amazon_client_secret' => 'amazon.client_secret',
        'amazon_refresh_token' => 'amazon.refresh_token',
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
    ];

    public function mount(): void
    {
        $this->form->fill([
            'company_name' => app(SettingsService::class)->get('company_name', ''),
            'packing_validation_enabled' => app(SettingsService::class)->get('packing_validation_enabled', true),
            'transparency_enabled' => app(SettingsService::class)->get('transparency_enabled', true),
            'batch_shipping_enabled' => app(SettingsService::class)->get('batch_shipping_enabled', true),
            'manual_shipping_enabled' => app(SettingsService::class)->get('manual_shipping_enabled', true),
            'carrier_api_timeout' => app(SettingsService::class)->get('carrier_api_timeout', 15),
            'audit_log_retention_days' => app(SettingsService::class)->get('audit_log_retention_days', 90),
            'sandbox_mode' => app(SettingsService::class)->get('sandbox_mode', false),
            'suppress_printing' => app(SettingsService::class)->get('suppress_printing', false),

            // Non-encrypted credential fields get their current values
            'usps_crid' => app(SettingsService::class)->get('usps.crid', config('services.usps.crid', '')),
            'usps_mid' => app(SettingsService::class)->get('usps.mid', config('services.usps.mid', '')),
            'fedex_account_number' => app(SettingsService::class)->get('fedex.account_number', config('services.fedex.account_number', '')),
            'ups_account_number' => app(SettingsService::class)->get('ups.account_number', config('services.ups.account_number', '')),
            'shopify_shop_domain' => app(SettingsService::class)->get('shopify.shop_domain', config('services.shopify.shop_domain', '')),
            'shopify_api_version' => app(SettingsService::class)->get('shopify.api_version', config('services.shopify.api_version', '2025-01')),
            'amazon_marketplace_id' => app(SettingsService::class)->get('amazon.marketplace_id', config('services.amazon.marketplace_id', 'ATVPDKIKX0DER')),

            // Encrypted fields are left empty — placeholder shows status
        ]);
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

                    Section::make('Data Retention')
                        ->description('Configure how long data is kept before automatic cleanup')
                        ->schema([
                            TextInput::make('audit_log_retention_days')
                                ->label('Audit Log Retention')
                                ->helperText('Audit log entries older than this will be automatically purged daily. Set to 0 to disable automatic cleanup.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(90)
                                ->suffix('days'),
                        ])
                        ->columns(1),

                    Section::make('USPS Credentials')
                        ->description('API credentials for USPS shipping services')
                        ->schema([
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
                        ])
                        ->columns(2)
                        ->collapsed(),

                    Section::make('FedEx Credentials')
                        ->description('API credentials for FedEx shipping services')
                        ->schema([
                            TextInput::make('fedex_api_key')
                                ->label('API Key')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('fedex.api_key', 'services.fedex.api_key')),
                            TextInput::make('fedex_api_secret')
                                ->label('API Secret')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('fedex.api_secret', 'services.fedex.api_secret')),
                            TextInput::make('fedex_account_number')
                                ->label('Account Number')
                                ->maxLength(50),
                        ])
                        ->columns(2)
                        ->collapsed(),

                    Section::make('UPS Credentials')
                        ->description('API credentials for UPS shipping services')
                        ->schema([
                            TextInput::make('ups_client_id')
                                ->label('Client ID')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('ups.client_id', 'services.ups.client_id')),
                            TextInput::make('ups_client_secret')
                                ->label('Client Secret')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('ups.client_secret', 'services.ups.client_secret')),
                            TextInput::make('ups_account_number')
                                ->label('Account Number')
                                ->maxLength(50),
                        ])
                        ->columns(2)
                        ->collapsed(),

                    Section::make('Shopify Integration')
                        ->description('Credentials for importing orders from Shopify')
                        ->schema([
                            TextInput::make('shopify_shop_domain')
                                ->label('Shop Domain')
                                ->placeholder('mystore.myshopify.com')
                                ->maxLength(255),
                            TextInput::make('shopify_api_version')
                                ->label('API Version')
                                ->placeholder('2025-01')
                                ->maxLength(20),
                            TextInput::make('shopify_client_id')
                                ->label('Client ID')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('shopify.client_id', 'services.shopify.client_id')),
                            TextInput::make('shopify_client_secret')
                                ->label('Client Secret')
                                ->password()
                                ->placeholder(fn () => $this->getCredentialPlaceholder('shopify.client_secret', 'services.shopify.client_secret')),
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
                        ])
                        ->columns(2)
                        ->collapsed(),

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
            'audit_log_retention_days' => (int) ($data['audit_log_retention_days'] ?? 90),
            'sandbox_mode' => $sandboxMode,
            'suppress_printing' => $suppressPrinting,
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

        app(SettingsService::class)->clearCache();

        // Clear cached OAuth tokens when sandbox mode or credentials change
        if ($sandboxMode !== $previousSandboxMode || $credentialsChanged) {
            Cache::forget('usps_authenticator');
            Cache::forget('usps_payment_authorization_token');
            Cache::forget('fedex_authenticator');
            Cache::forget('ups_authenticator');
            Cache::forget('amazon_sp_api_access_token');
            Cache::forget('shopify_access_token');
        }

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }
}
