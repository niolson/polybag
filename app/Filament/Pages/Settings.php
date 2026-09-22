<?php

namespace App\Filament\Pages;

use App\Enums\LabelReferenceSource;
use App\Enums\Role;
use App\Filament\Resources\LocationResource;
use App\Filament\Support\AddressForm;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Setting;
use App\Services\AccountLockoutService;
use App\Services\AddressReferenceService;
use App\Services\LabelReferenceResolver;
use App\Services\PasswordPolicyService;
use App\Services\PhoneParserService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Support\SvgUploadSanitizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class Settings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'App Settings';

    protected static UnitEnum|string|null $navigationGroup = 'Admin';

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

    public function mount(): void
    {
        $settings = app(SettingsService::class);
        $multiClientEnabled = $settings->get('multi_client_enabled', false);
        $multiLocationEnabled = $settings->get('multi_location_enabled', false);

        $formData = [
            'company_name' => $settings->get('company_name', ''),
            'pack_slip_logo' => $settings->get('pack_slip_logo'),
            'packing_validation_enabled' => $settings->get('packing_validation_enabled', true),
            'scan_to_add_enabled' => $settings->get('scan_to_add_enabled', false),
            'transparency_enabled' => $settings->get('transparency_enabled', true),
            'batch_shipping_enabled' => $settings->get('batch_shipping_enabled', true),
            'manual_shipping_enabled' => $settings->get('manual_shipping_enabled', true),
            'picking_enabled' => $settings->get('picking_enabled', false),
            'require_picking_before_shipping' => $settings->get('require_picking_before_shipping', false),
            'label_reference_source' => $settings->get('label_reference_source', LabelReferenceResolver::DEFAULT_SOURCE->value),
            'carrier_api_timeout' => $settings->get('carrier_api_timeout', 15),
            'address_validation_google_enabled' => $settings->get('address_validation_google_enabled', false),
            'audit_log_retention_days' => $settings->get('audit_log_retention_days', 365),
            'rate_quote_retention_days' => $settings->get('rate_quote_retention_days', 60),
            'shipping_offer_retention_days' => $settings->get('shipping_offer_retention_days', 7),
            'pii_retention_days' => $settings->get('pii_retention_days', 90),
            'archiving_enabled' => $settings->get('archiving_enabled', false),
            'archive_retention_days' => $settings->get('archive_retention_days', 365),
            'password_min_length' => $settings->get('password_min_length', PasswordPolicyService::DEFAULT_MIN_LENGTH),
            'password_require_mixed_case' => $settings->get('password_require_mixed_case', PasswordPolicyService::DEFAULT_REQUIRE_MIXED_CASE),
            'password_require_numbers' => $settings->get('password_require_numbers', PasswordPolicyService::DEFAULT_REQUIRE_NUMBERS),
            'password_require_symbols' => $settings->get('password_require_symbols', PasswordPolicyService::DEFAULT_REQUIRE_SYMBOLS),
            'password_expiration_days' => $settings->get('password_expiration_days', PasswordPolicyService::DEFAULT_EXPIRATION_DAYS),
            'password_history_count' => $settings->get('password_history_count', PasswordPolicyService::DEFAULT_HISTORY_COUNT),
            'password_min_age_days' => $settings->get('password_min_age_days', PasswordPolicyService::DEFAULT_MIN_AGE_DAYS),
            'google_sso_enabled' => $settings->get('google_sso_enabled', false),
            'azure_sso_enabled' => $settings->get('azure_sso_enabled', false),
            'require_mfa' => $settings->get('require_mfa', false),
            'trust_idp_mfa' => $settings->get('trust_idp_mfa', false),
            'trusted_azure_tids' => $settings->get('trusted_azure_tids', []),
            'account_lockout_max_attempts' => $settings->get('account_lockout_max_attempts', AccountLockoutService::DEFAULT_MAX_ATTEMPTS),
            'account_lockout_minutes' => $settings->get('account_lockout_minutes', AccountLockoutService::DEFAULT_LOCKOUT_MINUTES),
        ];

        if (! $multiClientEnabled) {
            $client = Client::where('is_default', true)->first();
            if ($client) {
                $formData['client'] = [
                    'logo' => $client->logo,
                    'company_name' => $client->company_name,
                    'custom_message' => $client->custom_message,
                    'return_instructions' => $client->return_instructions,
                    'return_company' => $client->return_company,
                    'return_name' => $client->return_name,
                    'return_address1' => $client->return_address1,
                    'return_address2' => $client->return_address2,
                    'return_city' => $client->return_city,
                    'return_state_or_province' => $client->return_state_or_province,
                    'return_postal_code' => $client->return_postal_code,
                    'return_country' => $client->return_country,
                    'return_phone' => $client->return_phone,
                    'blind_purchase_enabled' => $client->blind_purchase_enabled,
                ];
            }
        }

        if (! $multiLocationEnabled) {
            $location = Location::where('is_default', true)->first();
            if ($location) {
                $formData['location'] = [
                    'name' => $location->name,
                    'timezone' => $location->timezone,
                    'fedex_hub_id' => $location->fedex_hub_id,
                    'company' => $location->company,
                    'first_name' => $location->first_name,
                    'last_name' => $location->last_name,
                    'address1' => $location->address1,
                    'address2' => $location->address2,
                    'city' => $location->city,
                    'state_or_province' => $location->state_or_province,
                    'postal_code' => $location->postal_code,
                    'country' => $location->country,
                    'phone' => $location->phone,
                    'carrierLocations' => $location->carrierLocations
                        ->map(fn ($cl): array => [
                            'carrier_id' => $cl->carrier_id,
                            'pickup_days' => $cl->pickup_days ?? [],
                        ])
                        ->toArray(),
                ];
            }
        }

        $this->form->fill($formData);
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
                            FileUpload::make('pack_slip_logo')
                                ->label('Pack Slip Logo')
                                ->helperText('Logo printed on pack slips. Recommended: landscape image, PNG or JPG.')
                                ->disk('public')
                                ->directory('logos')
                                ->visibility('public')
                                ->image()
                                ->panelLayout('grid')
                                ->maxSize(10240)
                                ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'])
                                ->saveUploadedFileUsing(SvgUploadSanitizer::saveUsing())
                                ->visible(fn (): bool => (bool) app(SettingsService::class)->get('multi_client_enabled', false)),
                        ])
                        ->columns(1),

                    Section::make('Pack Slip')
                        ->description('Branding and messaging printed on pack slips.')
                        ->visible(fn (): bool => ! (bool) app(SettingsService::class)->get('multi_client_enabled', false))
                        ->schema([
                            FileUpload::make('client.logo')
                                ->label('Logo')
                                ->helperText('Logo printed on pack slips. Recommended: landscape image, PNG or JPG.')
                                ->disk('public')
                                ->directory('logos')
                                ->visibility('public')
                                ->image()
                                ->panelLayout('grid')
                                ->maxSize(10240)
                                ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'])
                                ->saveUploadedFileUsing(SvgUploadSanitizer::saveUsing())
                                ->columnSpanFull(),
                            TextInput::make('client.company_name')
                                ->label('Company Name')
                                ->maxLength(255)
                                ->helperText('Name shown in the return address on pack slips. Defaults to company name if blank.')
                                ->columnSpanFull(),
                            Textarea::make('client.custom_message')
                                ->label('Custom Message')
                                ->rows(3)
                                ->maxLength(500)
                                ->helperText('Optional message printed at the bottom of each pack slip.')
                                ->columnSpanFull(),
                            Textarea::make('client.return_instructions')
                                ->label('Return Instructions')
                                ->rows(3)
                                ->maxLength(500)
                                ->helperText('Optional return instructions printed at the bottom of each pack slip.')
                                ->columnSpanFull(),
                        ])
                        ->columns(1),

                    Section::make('Return Address')
                        ->description('Ship-from address shown on labels for outgoing shipments. Leave blank to use the warehouse address.')
                        ->visible(fn (): bool => ! (bool) app(SettingsService::class)->get('multi_client_enabled', false))
                        ->collapsible()
                        ->schema([
                            TextInput::make('client.return_company')
                                ->label('Company')
                                ->maxLength(255)
                                ->columnSpanFull(),
                            TextInput::make('client.return_name')
                                ->label('Contact Name')
                                ->maxLength(255)
                                ->columnSpanFull(),
                            TextInput::make('client.return_address1')
                                ->label('Address')
                                ->maxLength(255)
                                ->columnSpanFull(),
                            TextInput::make('client.return_address2')
                                ->label('Apartment, suite, etc.')
                                ->maxLength(255)
                                ->columnSpanFull(),
                            Grid::make(['default' => 1, 'md' => 3])
                                ->schema([
                                    TextInput::make('client.return_city')
                                        ->label('City')
                                        ->maxLength(255),
                                    TextInput::make('client.return_state_or_province')
                                        ->label('State / Province')
                                        ->maxLength(100),
                                    TextInput::make('client.return_postal_code')
                                        ->label('Postal Code')
                                        ->maxLength(20),
                                ])
                                ->columnSpanFull(),
                            Select::make('client.return_country')
                                ->label('Country')
                                ->options(fn (): array => app(AddressReferenceService::class)->getCountryOptions())
                                ->searchable()
                                ->native(false)
                                ->columnSpanFull(),
                            TextInput::make('client.return_phone')
                                ->label('Phone')
                                ->tel()
                                ->maxLength(50)
                                ->columnSpanFull(),
                        ])
                        ->columns(1),

                    Section::make('Blind Purchase')
                        ->description('Postage bought on a sales channel\'s own account, at a price and on a service nobody sees until afterwards.')
                        // Single-client installs edit their one client here;
                        // multi-client installs edit it per client, which is
                        // where this consent belongs (ADR-0003 decision 5).
                        ->visible(fn (): bool => ! (bool) app(SettingsService::class)->get('multi_client_enabled', false))
                        ->schema([
                            Toggle::make('client.blind_purchase_enabled')
                                ->label('Allow blind purchase')
                                ->helperText('Shopify Shipping reaches USPS Connect eCommerce rates without an account of our own, but reports no price or service, and no carrier until the label comes back. Its labels cannot be voided from PolyBag. Off by default. Auto-ship and batch ship use it only when a shipping rule selects it or it is the shipping method\'s sole eligible choice.'),
                        ])
                        ->columns(1),

                    Section::make('Ship-From Address')
                        ->description('Managed in Settings > Locations. The default location is used as the warehouse address on labels.')
                        ->visible(fn (): bool => (bool) app(SettingsService::class)->get('multi_location_enabled', false))
                        ->schema([
                            Placeholder::make('default_location')
                                ->label('Default Location')
                                ->content(fn () => Location::getDefault()?->name ?? 'No default location set'),
                        ]),

                    Section::make('Warehouse')
                        ->description('Ship-from address and details for your warehouse.')
                        ->visible(fn (): bool => ! (bool) app(SettingsService::class)->get('multi_location_enabled', false))
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextInput::make('location.name')
                                        ->label('Location Name')
                                        ->required()
                                        ->maxLength(255),
                                    Select::make('location.timezone')
                                        ->label('Timezone')
                                        ->options(fn () => collect(timezone_identifiers_list())
                                            ->filter(fn ($tz): bool => str_starts_with($tz, 'America/') || str_starts_with($tz, 'Pacific/') || str_starts_with($tz, 'US/'))
                                            ->mapWithKeys(fn ($tz): array => [$tz => str_replace('_', ' ', $tz)]))
                                        ->searchable()
                                        ->required(),
                                    Select::make('location.fedex_hub_id')
                                        ->label('FedEx Hub ID')
                                        ->helperText('Used for FedEx Ground Economy / SmartPost shipments from this origin.')
                                        ->options(config('fedex.ground_economy_hubs'))
                                        ->searchable()
                                        ->placeholder('Select a FedEx hub')
                                        ->visible(fn (): bool => LocationResource::hasActiveFedexCarrier())
                                        ->columnSpanFull(),
                                ]),
                            ...AddressForm::recipientAddressFields(
                                prefix: 'location.',
                                includeCompany: true,
                                includePhone: true,
                                requireNames: true,
                                requirePostalCode: true,
                                postalCodeMaxLength: 20,
                                phoneMaxLength: 20,
                            ),
                        ])
                        ->columns(1),

                    Section::make('Carrier Pickup Schedule')
                        ->description('Which days each carrier collects from your warehouse. A carrier you do not list here is picked up Monday-Friday.')
                        ->visible(fn (): bool => ! (bool) app(SettingsService::class)->get('multi_location_enabled', false))
                        ->collapsible()
                        ->schema([
                            Repeater::make('location.carrierLocations')
                                ->hiddenLabel()
                                ->schema([
                                    Select::make('carrier_id')
                                        ->label('Carrier')
                                        ->options(fn () => Carrier::active()->pluck('name', 'id'))
                                        ->required()
                                        ->live()
                                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                    CheckboxList::make('pickup_days')
                                        ->label('Pickup Days')
                                        ->helperText('Ship dates land only on these days. Removing a carrier from this list does not stop scheduling it — it returns that carrier to the Monday-Friday default.')
                                        ->options([
                                            0 => 'Sunday',
                                            1 => 'Monday',
                                            2 => 'Tuesday',
                                            3 => 'Wednesday',
                                            4 => 'Thursday',
                                            5 => 'Friday',
                                            6 => 'Saturday',
                                        ])
                                        ->default([1, 2, 3, 4, 5])
                                        ->minItems(1)
                                        ->columns(7),
                                ])
                                ->defaultItems(0)
                                ->addActionLabel('Add Carrier')
                                ->columnSpanFull(),
                        ]),

                    Section::make('Features')
                        ->description('Enable or disable application features')
                        ->schema([
                            Toggle::make('packing_validation_enabled')
                                ->label('Packing Validation')
                                ->helperText('When enabled, all items must be scanned before shipping. When disabled, only weight and dimensions are required.')
                                ->default(true),
                            Toggle::make('scan_to_add_enabled')
                                ->label('Scan-to-Add Mode')
                                ->helperText('When enabled, shipments that arrive without line-item data can be packed by scanning products to record what was actually packed. Shipments with items always use the standard validate mode.')
                                ->default(false),
                            Toggle::make('batch_shipping_enabled')
                                ->label('Batch Shipping')
                                ->helperText('When enabled, admins can select multiple shipments and generate labels in bulk.')
                                ->default(true),
                            Toggle::make('manual_shipping_enabled')
                                ->label('Manual Shipping')
                                ->helperText('When enabled, the Manual Ship page is available for creating ad-hoc shipments.')
                                ->default(true),
                            Toggle::make('picking_enabled')
                                ->label('Picking')
                                ->helperText('When enabled, pickers can create pick batches and print picking summaries before packing.')
                                ->default(false)
                                ->live(),
                            Toggle::make('require_picking_before_shipping')
                                ->label('Require Picking Before Shipping')
                                ->helperText('When enabled, shipments must be picked before they can be packed or batch shipped. Applies to all open shipments, including those created before picking was enabled.')
                                ->default(false)
                                ->visible(fn (Get $get): bool => (bool) $get('picking_enabled')),
                            Toggle::make('transparency_enabled')
                                ->label('Amazon Transparency Program')
                                ->helperText('When enabled, shipment items requiring transparency codes will prompt for code scanning during packing.')
                                ->default(false),
                        ])
                        ->columns(1),

                    Section::make('Shipping Labels')
                        ->description('What carriers print on the labels this instance buys')
                        ->schema([
                            Select::make('label_reference_source')
                                ->label('Reference Printed on Labels')
                                ->options(LabelReferenceSource::class)
                                ->default(LabelReferenceResolver::DEFAULT_SOURCE->value)
                                ->selectablePlaceholder(false)
                                ->native(false)
                                ->helperText('Printed in the carrier\'s reference field so a label can be matched back to its package. USPS and UPS print it as text; FedEx prints it after "REF:". Carriers cut it to their own length limits. Individual clients can override this.'),
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

                    Section::make('Address Validation')
                        ->description('Fallback address validation for tenants without a licensed USPS Addresses API account, or for non-US addresses')
                        ->schema([
                            Toggle::make('address_validation_google_enabled')
                                ->label('Enable Google Address Validation Fallback')
                                ->helperText('When enabled, Google Address Validation is used for shipments USPS cannot validate (no US account/license, or non-US addresses). Billed to PolyBag, not this tenant — leave off unless you want this fallback.')
                                ->default(false),
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
                                ->default(PasswordPolicyService::DEFAULT_MIN_LENGTH)
                                ->suffix('characters'),
                            Toggle::make('password_require_mixed_case')
                                ->label('Require Mixed Case')
                                ->helperText('Require at least one uppercase and one lowercase letter.')
                                ->default(PasswordPolicyService::DEFAULT_REQUIRE_MIXED_CASE),
                            Toggle::make('password_require_numbers')
                                ->label('Require Numbers')
                                ->helperText('Require at least one numeric character.')
                                ->default(PasswordPolicyService::DEFAULT_REQUIRE_NUMBERS),
                            Toggle::make('password_require_symbols')
                                ->label('Require Symbols')
                                ->helperText('Require at least one special character.')
                                ->default(PasswordPolicyService::DEFAULT_REQUIRE_SYMBOLS),
                            TextInput::make('password_expiration_days')
                                ->label('Password Expiration')
                                ->helperText('Force users to change passwords after this many days.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(PasswordPolicyService::DEFAULT_EXPIRATION_DAYS)
                                ->suffix('days'),
                            TextInput::make('password_history_count')
                                ->label('Password History')
                                ->helperText('Number of most recent passwords (including the current one) a user may not reuse.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(24)
                                ->default(PasswordPolicyService::DEFAULT_HISTORY_COUNT)
                                ->suffix('passwords'),
                            TextInput::make('password_min_age_days')
                                ->label('Minimum Password Age')
                                ->helperText('Block users from voluntarily changing their password again until this many days have passed. Prevents rapid password cycling used to bypass the history control above. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(30)
                                ->default(PasswordPolicyService::DEFAULT_MIN_AGE_DAYS)
                                ->suffix('days'),
                        ])
                        ->columns(2),

                    Section::make('Authentication')
                        ->description('Authentication settings for all users')
                        ->schema([
                            Toggle::make('require_mfa')
                                ->label('Require Multi-Factor Authentication')
                                ->helperText('When enabled, all users must set up MFA before accessing the app. Users with an email address may use email or authenticator app codes; users without an email must use an authenticator app. Required while an active Amazon SP-API connection exists, since it gives access to customer PII.')
                                ->default(false),
                            Toggle::make('google_sso_enabled')
                                ->label('Google SSO')
                                ->helperText('Show "Sign in with Google" button on the login page. Requires Google OAuth credentials in .env (or broker configured).')
                                ->default(false),
                            Toggle::make('azure_sso_enabled')
                                ->label('Azure / Microsoft Entra SSO')
                                ->helperText('Show "Sign in with Microsoft" button on the login page. Requires Azure credentials in .env (or broker configured).')
                                ->default(false),
                            Toggle::make('trust_idp_mfa')
                                ->label('Trust Entra MFA (skip app challenge)')
                                ->helperText('When a user signs in via Microsoft Entra SSO and Entra asserts MFA was performed, skip this app\'s own MFA challenge instead of double-prompting. Only applies to the trusted tenant IDs below, and only makes sense for tenants that enforce MFA (Conditional Access). Leave off otherwise — a login is never admitted on a missing assertion.')
                                ->live()
                                ->default(false),
                            TagsInput::make('trusted_azure_tids')
                                ->label('Trusted Entra tenant IDs')
                                ->helperText('Only Microsoft Entra logins whose tenant ID (tid) is listed here can satisfy MFA. Add the GUID of each Entra tenant whose MFA you trust. Empty means no login is trusted.')
                                ->placeholder('00000000-0000-0000-0000-000000000000')
                                ->nestedRecursiveRules(['uuid'])
                                ->visible(fn (Get $get): bool => (bool) $get('trust_idp_mfa'))
                                ->columnSpanFull(),
                            TextInput::make('account_lockout_max_attempts')
                                ->label('Account Lockout Threshold')
                                ->helperText('Lock an account after this many consecutive failed login attempts.')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(10)
                                ->default(AccountLockoutService::DEFAULT_MAX_ATTEMPTS)
                                ->suffix('attempts'),
                            TextInput::make('account_lockout_minutes')
                                ->label('Account Lockout Duration')
                                ->helperText('How long a locked account stays locked before it automatically unlocks.')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(1440)
                                ->default(AccountLockoutService::DEFAULT_LOCKOUT_MINUTES)
                                ->suffix('minutes'),
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
                            TextInput::make('shipping_offer_retention_days')
                                ->label('Shipping Offer Retention')
                                ->helperText('Purchase context behind a quoted rate is kept this long, then purged daily. Days rather than months: an offer is spent or abandoned within minutes. An offer that was spent without the carrier confirming a purchase is never purged, whatever this is set to. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(365)
                                ->default(7)
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

        // Validate location phone before saving anything
        if (! app(SettingsService::class)->get('multi_location_enabled', false) && isset($data['location'])) {
            $phone = filled($data['location']['phone'] ?? null) ? trim((string) $data['location']['phone']) : null;
            if ($phone !== null) {
                $country = (string) ($data['location']['country'] ?? 'US');
                $result = PhoneParserService::parse($phone, $country);
                if (! $result->isValid()) {
                    $message = "Enter a valid phone number for {$country}. If the phone number belongs to a different country, use international format, such as +14155550132.";
                    Notification::make()->title('Invalid phone number')->body($message)->danger()->send();
                    $this->addError('data.location.phone', $message);

                    return;
                }
            }
        }

        // A Select backed by an enum hands back the enum case, not its value.
        $labelReferenceSource = $data['label_reference_source'] ?? null;
        $labelReferenceSource = $labelReferenceSource instanceof LabelReferenceSource
            ? $labelReferenceSource
            : LabelReferenceSource::tryFrom((string) $labelReferenceSource);

        // Map form fields to setting keys
        $settings = [
            'company_name' => $data['company_name'] ?? '',
            'pack_slip_logo' => $data['pack_slip_logo'] ?? null,
            'packing_validation_enabled' => $data['packing_validation_enabled'] ?? true,
            'scan_to_add_enabled' => (bool) ($data['scan_to_add_enabled'] ?? false),
            'transparency_enabled' => $data['transparency_enabled'] ?? true,
            'batch_shipping_enabled' => $data['batch_shipping_enabled'] ?? true,
            'manual_shipping_enabled' => $data['manual_shipping_enabled'] ?? true,
            'picking_enabled' => (bool) ($data['picking_enabled'] ?? false),
            'require_picking_before_shipping' => (bool) ($data['require_picking_before_shipping'] ?? false),
            'label_reference_source' => ($labelReferenceSource ?? LabelReferenceResolver::DEFAULT_SOURCE)->value,
            'carrier_api_timeout' => (int) ($data['carrier_api_timeout'] ?? 15),
            'address_validation_google_enabled' => (bool) ($data['address_validation_google_enabled'] ?? false),
            'audit_log_retention_days' => (int) ($data['audit_log_retention_days'] ?? 365),
            'rate_quote_retention_days' => (int) ($data['rate_quote_retention_days'] ?? 60),
            'shipping_offer_retention_days' => (int) ($data['shipping_offer_retention_days'] ?? 7),
            'pii_retention_days' => (int) ($data['pii_retention_days'] ?? 90),
            'archiving_enabled' => (bool) ($data['archiving_enabled'] ?? false),
            'archive_retention_days' => (int) ($data['archive_retention_days'] ?? 365),
            'password_min_length' => (int) ($data['password_min_length'] ?? PasswordPolicyService::DEFAULT_MIN_LENGTH),
            'password_require_mixed_case' => (bool) ($data['password_require_mixed_case'] ?? PasswordPolicyService::DEFAULT_REQUIRE_MIXED_CASE),
            'password_require_numbers' => (bool) ($data['password_require_numbers'] ?? PasswordPolicyService::DEFAULT_REQUIRE_NUMBERS),
            'password_require_symbols' => (bool) ($data['password_require_symbols'] ?? PasswordPolicyService::DEFAULT_REQUIRE_SYMBOLS),
            'password_expiration_days' => (int) ($data['password_expiration_days'] ?? PasswordPolicyService::DEFAULT_EXPIRATION_DAYS),
            'password_history_count' => (int) ($data['password_history_count'] ?? PasswordPolicyService::DEFAULT_HISTORY_COUNT),
            'password_min_age_days' => (int) ($data['password_min_age_days'] ?? PasswordPolicyService::DEFAULT_MIN_AGE_DAYS),
            'google_sso_enabled' => (bool) ($data['google_sso_enabled'] ?? false),
            'azure_sso_enabled' => (bool) ($data['azure_sso_enabled'] ?? false),
            'require_mfa' => (bool) ($data['require_mfa'] ?? false),
            'trust_idp_mfa' => (bool) ($data['trust_idp_mfa'] ?? false),
            'account_lockout_max_attempts' => (int) ($data['account_lockout_max_attempts'] ?? AccountLockoutService::DEFAULT_MAX_ATTEMPTS),
            'account_lockout_minutes' => (int) ($data['account_lockout_minutes'] ?? AccountLockoutService::DEFAULT_LOCKOUT_MINUTES),
        ];

        if (! $settings['require_mfa'] && DataSource::where('source_type', AmazonSource::class)->where('active', true)->exists()) {
            $message = 'Multi-Factor Authentication cannot be disabled while an active Amazon SP-API connection exists — it gives access to customer PII. Deactivate the Amazon connection first (Integrations → Connections).';

            Notification::make()
                ->title('Cannot disable Multi-Factor Authentication')
                ->body($message)
                ->danger()
                ->send();

            $this->addError('data.require_mfa', $message);

            return;
        }

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

        // Persist the trusted Entra tenant-id allowlist as JSON (a list, not a
        // scalar), trimming blanks so an empty list reliably means "trust none".
        // Only rewrite it when the field was actually submitted — it's hidden
        // (and dehydrated out of state) while trust_idp_mfa is off, and we don't
        // want toggling the feature off to silently wipe a configured allowlist.
        if (array_key_exists('trusted_azure_tids', $data)) {
            // Entra tenant ids are case-insensitive UUIDs; store them canonically
            // (lowercased, de-duplicated) so matching is casing-independent.
            $trustedTids = array_values(array_unique(array_filter(array_map(
                fn ($tid): string => strtolower(trim((string) $tid)),
                is_array($data['trusted_azure_tids']) ? $data['trusted_azure_tids'] : [],
            ))));
            app(SettingsService::class)->set('trusted_azure_tids', $trustedTids, type: 'json');
        }

        app(SettingsService::class)->clearCache();

        // Save default client fields in single-client mode
        if (! app(SettingsService::class)->get('multi_client_enabled', false) && isset($data['client'])) {
            $client = Client::where('is_default', true)->first();
            if ($client) {
                $client->update([
                    'logo' => $data['client']['logo'] ?? null,
                    'company_name' => $data['client']['company_name'] ?? null,
                    'custom_message' => $data['client']['custom_message'] ?? null,
                    'return_instructions' => $data['client']['return_instructions'] ?? null,
                    'return_company' => $data['client']['return_company'] ?? null,
                    'return_name' => $data['client']['return_name'] ?? null,
                    'return_address1' => $data['client']['return_address1'] ?? null,
                    'return_address2' => $data['client']['return_address2'] ?? null,
                    'return_city' => $data['client']['return_city'] ?? null,
                    'return_state_or_province' => $data['client']['return_state_or_province'] ?? null,
                    'return_postal_code' => $data['client']['return_postal_code'] ?? null,
                    'return_country' => $data['client']['return_country'] ?? null,
                    'return_phone' => $data['client']['return_phone'] ?? null,
                    'blind_purchase_enabled' => (bool) ($data['client']['blind_purchase_enabled'] ?? false),
                ]);
            }
        }

        // Save default location fields in single-location mode
        if (! app(SettingsService::class)->get('multi_location_enabled', false) && isset($data['location'])) {
            $location = Location::where('is_default', true)->first();
            if ($location) {
                $locationFields = $data['location'];
                $carrierLocationsData = $locationFields['carrierLocations'] ?? [];
                unset($locationFields['carrierLocations']);

                $location->update($locationFields);
                Location::clearDefaultCache();

                // Sync carrier locations by carrier_id (preserves last_end_of_day_at)
                $submittedCarrierIds = [];
                foreach ($carrierLocationsData as $item) {
                    if (filled($item['carrier_id'] ?? null)) {
                        $location->carrierLocations()->updateOrCreate(
                            ['carrier_id' => $item['carrier_id']],
                            ['pickup_days' => $item['pickup_days'] ?? []],
                        );
                        $submittedCarrierIds[] = $item['carrier_id'];
                    }
                }
                $location->carrierLocations()->whereNotIn('carrier_id', $submittedCarrierIds)->delete();
            }
        }

        $this->rememberData();

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }
}
