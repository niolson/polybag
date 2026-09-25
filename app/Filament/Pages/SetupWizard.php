<?php

namespace App\Filament\Pages;

use App\Enums\AmazonMarketplace;
use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Enums\Role;
use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use App\Filament\Resources\DataSources\DataSourceResource;
use App\Filament\Support\AddressForm;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\ShippingMethod;
use App\Services\CacheService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\ImportConnectionConfig;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Database\Seeders\BoxSizeSeeder;
use Database\Seeders\ShippingMethodSeeder;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class SetupWizard extends Page
{
    protected static ?string $slug = 'setup';

    protected static ?string $title = 'Setup Wizard';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.setup-wizard';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->role->isAtLeast(Role::Admin)
            && ! app(SettingsService::class)->get('setup_complete', false);
    }

    public function mount(): void
    {
        if (app(SettingsService::class)->get('setup_complete', false)) {
            $this->redirect('/');

            return;
        }

        $location = Location::getDefault();
        $settings = app(SettingsService::class);

        $this->form->fill([
            // Step 1: Company info
            'company_name' => $settings->get('company_name', ''),
            'location_name' => $location?->name ?? 'Main Warehouse',
            'location_company' => $location?->company,
            'location_first_name' => $location?->first_name,
            'location_last_name' => $location?->last_name,
            'location_address1' => $location?->address1,
            'location_address2' => $location?->address2,
            'location_city' => $location?->city,
            'location_state_or_province' => $location?->state_or_province,
            'location_postal_code' => $location?->postal_code,
            'location_country' => $location?->country ?? 'US',
            'location_phone' => $location?->phone,
            'location_timezone' => $location?->timezone ?? 'America/New_York',

            // Step 2: Carriers
            'carrier_usps_active' => Carrier::where('name', Carrier::USPS)->value('active') ?? false,
            'carrier_usps_services' => CarrierService::whereHas('carrier', fn ($q) => $q->where('name', Carrier::USPS))->where('active', true)->pluck('id')->toArray(),
            'carrier_fedex_active' => Carrier::where('name', Carrier::FEDEX)->value('active') ?? false,
            'carrier_fedex_services' => CarrierService::whereHas('carrier', fn ($q) => $q->where('name', Carrier::FEDEX))->where('active', true)->pluck('id')->toArray(),
            'carrier_ups_active' => Carrier::where('name', Carrier::UPS)->value('active') ?? false,
            'carrier_ups_services' => CarrierService::whereHas('carrier', fn ($q) => $q->where('name', Carrier::UPS))->where('active', true)->pluck('id')->toArray(),

            // Step 3: Box sizes (repeater is for new additions only)
            'prepopulate_box_sizes' => false,
            'box_sizes' => [],

            // Step 4: Channels & shipping methods (repeaters are for new additions only)
            'channels' => [],
            'prepopulate_shipping_methods' => false,
            'shipping_methods' => [],

            // Step 5: Order import (prefill from a connection that imports orders;
            // a postage-only connection is not an import source)
            'import_source' => match (DataSource::importing()->value('source_type')) {
                DatabaseSource::class => 'database',
                ShopifySource::class => 'shopify',
                AmazonSource::class => 'amazon',
                default => 'none',
            },
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(SettingsService::class);
        $startStep = (int) $settings->get('setup_wizard_step', 1);

        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    $this->companyInfoStep(),
                    $this->carriersStep(),
                    $this->boxSizesStep(),
                    $this->channelsAndMethodsStep(),
                    $this->importSourceStep(),
                    $this->summaryStep(),
                ])
                    ->startOnStep($startStep)
                    ->persistStepInQueryString('step')
                    ->cancelAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button
                            type="button"
                            wire:click="skipWizard"
                            wire:confirm="Are you sure? You can configure all of these settings later from the Settings menu."
                            color="gray"
                            outlined
                            size="sm"
                        >
                            Skip wizard
                        </x-filament::button>
                    BLADE)))
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="button" wire:click="completeSetup" icon="heroicon-o-check">
                            Complete Setup
                        </x-filament::button>
                    BLADE))),
            ]);
    }

    // ──────────────────────────────────────────────
    // Step Definitions
    // ──────────────────────────────────────────────

    private function companyInfoStep(): Step
    {
        return Step::make('Company Info')
            ->icon('heroicon-o-building-office')
            ->description('Company name and ship-from address')
            ->schema([
                Forms\Components\TextInput::make('company_name')
                    ->label('Company Name')
                    ->required()
                    ->maxLength(255),
                Section::make('Ship-From Address')
                    ->schema([
                        Forms\Components\TextInput::make('location_name')
                            ->label('Location Name')
                            ->required()
                            ->maxLength(255)
                            ->default('Main Warehouse'),
                        ...AddressForm::recipientAddressFields(
                            prefix: 'location_',
                            includeCompany: true,
                            includePhone: true,
                            requireNames: true,
                            requirePostalCode: true,
                            postalCodeMaxLength: 20,
                        ),
                        Forms\Components\Select::make('location_timezone')
                            ->label('Timezone')
                            ->options(fn () => collect(timezone_identifiers_list())
                                ->filter(fn ($tz): bool => str_starts_with($tz, 'America/') || str_starts_with($tz, 'Pacific/') || str_starts_with($tz, 'US/'))
                                ->mapWithKeys(fn ($tz): array => [$tz => str_replace('_', ' ', $tz)]))
                            ->searchable()
                            ->default('America/New_York')
                            ->required(),
                    ])
                    ->columns(2),
            ])
            ->afterValidation(function (): void {
                $this->saveCompanyInfo();
                $this->advanceStep(2);
            });
    }

    private function carriersStep(): Step
    {
        return Step::make('Carriers')
            ->icon('heroicon-o-truck')
            ->description('Select carriers and services to enable')
            ->schema([
                $this->carrierSection(Carrier::USPS, 'usps'),
                $this->carrierSection(Carrier::FEDEX, 'fedex'),
                $this->carrierSection(Carrier::UPS, 'ups'),
            ])
            ->afterValidation(function (): void {
                $this->saveCarriers();
                $this->advanceStep(3);
            });
    }

    private function boxSizesStep(): Step
    {
        return Step::make('Box Sizes')
            ->icon('heroicon-o-square-3-stack-3d')
            ->description('Define your box sizes')
            ->schema([
                Forms\Components\Toggle::make('prepopulate_box_sizes')
                    ->label('Load recommended starter box sizes')
                    ->helperText('Adds a reusable starter set of common box sizes and mailers. You can edit or remove them later.')
                    ->default(false),
                Forms\Components\Placeholder::make('box_size_notice')
                    ->label('')
                    ->content('Box sizes speed up the packing workflow by pre-filling dimensions. You can skip this step and enter dimensions manually when shipping, or add box sizes later.'),
                Forms\Components\Placeholder::make('existing_box_sizes')
                    ->label('Existing Box Sizes')
                    ->visible(fn () => BoxSize::exists())
                    ->content(fn (): HtmlString => new HtmlString(
                        view('filament.pages.setup-wizard.existing-box-sizes', [
                            'boxes' => BoxSize::all(),
                        ])->render()
                    )),
                Forms\Components\Repeater::make('box_sizes')
                    ->label('Add New Box Sizes')
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('type')
                            ->options(BoxSizeType::class)
                            ->required()
                            ->default(BoxSizeType::BOX),
                        Forms\Components\TextInput::make('height')
                            ->numeric()
                            ->required()
                            ->suffix('in')
                            ->minValue(0.01)
                            ->maxValue(999),
                        Forms\Components\TextInput::make('width')
                            ->numeric()
                            ->required()
                            ->suffix('in')
                            ->minValue(0.01)
                            ->maxValue(999),
                        Forms\Components\TextInput::make('length')
                            ->numeric()
                            ->required()
                            ->suffix('in')
                            ->minValue(0.01)
                            ->maxValue(999),
                        Forms\Components\TextInput::make('max_weight')
                            ->label('Max Weight')
                            ->numeric()
                            ->required()
                            ->suffix('lbs')
                            ->minValue(0.01)
                            ->maxValue(150),
                        Forms\Components\TextInput::make('empty_weight')
                            ->label('Empty Weight')
                            ->numeric()
                            ->suffix('lbs')
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(50),
                        Forms\Components\Select::make('carrier_packaging')
                            ->label('Carrier packaging')
                            ->options(CarrierPackaging::groupedOptions())
                            ->nullable()
                            ->placeholder('Own packaging')
                            ->helperText('A carrier packaging is only useful for a carrier whose account or reseller can rate it: declaring a FedEx Pak on a UPS-only install simply yields no matching rates.'),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Add Box Size')
                    ->reorderable(false),
            ])
            ->afterValidation(function (): void {
                $this->saveBoxSizes();
                $this->advanceStep(4);
            });
    }

    private function channelsAndMethodsStep(): Step
    {
        return Step::make('Channels & Shipping')
            ->icon('heroicon-o-globe-alt')
            ->description('Set up sales channels and shipping methods')
            ->schema([
                Section::make('Channels')
                    ->description('Sales channels represent where orders come from (e.g. Shopify, Amazon, manual entry).')
                    ->schema([
                        Forms\Components\Placeholder::make('existing_channels')
                            ->label('Existing Channels')
                            ->visible(fn () => Channel::exists())
                            ->content(fn (): HtmlString => new HtmlString(
                                view('filament.pages.setup-wizard.existing-channels', [
                                    'channels' => Channel::with('aliases')->get(),
                                ])->render()
                            )),
                        Forms\Components\Repeater::make('channels')
                            ->label('Add New Channels')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Select::make('icon')
                                    ->options(fn () => collect([
                                        'heroicon-o-shopping-bag' => 'Shopping Bag',
                                        'heroicon-o-shopping-cart' => 'Shopping Cart',
                                        'heroicon-o-building-storefront' => 'Storefront',
                                        'heroicon-o-globe-alt' => 'Globe',
                                        'heroicon-o-device-phone-mobile' => 'Mobile',
                                        'heroicon-o-pencil-square' => 'Manual',
                                        'heroicon-o-inbox-stack' => 'Inbox',
                                        'heroicon-o-truck' => 'Truck',
                                    ])->mapWithKeys(fn (string $label, string $icon): array => [
                                        $icon => '<span class="flex items-center gap-2">'
                                            .svg($icon, 'w-5 h-5')->toHtml()
                                            ."<span>{$label}</span></span>",
                                    ])->all())
                                    ->allowHtml()
                                    ->nullable()
                                    ->searchable(),
                                Forms\Components\TagsInput::make('aliases')
                                    ->label('Aliases')
                                    ->placeholder('Add alias')
                                    ->helperText('Reference values from your import source that map to this channel.'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add Channel')
                            ->reorderable(false),
                    ]),
                Section::make('Shipping Methods')
                    ->description('Shipping methods define how orders are fulfilled (e.g. Standard Ground, Priority, Express).')
                    ->schema([
                        Forms\Components\Toggle::make('prepopulate_shipping_methods')
                            ->label('Load recommended starter shipping methods')
                            ->helperText('Adds a starter set of common shipping methods mapped to supported carrier services. You can edit or remove them later.')
                            ->default(false)
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('existing_methods')
                            ->label('Existing Shipping Methods')
                            ->visible(fn () => ShippingMethod::exists())
                            ->content(fn (): HtmlString => new HtmlString(
                                view('filament.pages.setup-wizard.existing-methods', [
                                    'methods' => ShippingMethod::with(['aliases', 'carrierServices.carrier'])->get(),
                                ])->render()
                            )),
                        Forms\Components\Placeholder::make('alias_hint')
                            ->label('')
                            ->content('If you know the reference values your import source will send, add them as aliases. Otherwise, map them later using the Unmapped References tools in Settings.'),
                        Forms\Components\Repeater::make('shipping_methods')
                            ->label('Add New Shipping Methods')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('commitment_days')
                                    ->label('Commitment Days')
                                    ->numeric()
                                    ->nullable(),
                                Forms\Components\CheckboxList::make('carrier_services')
                                    ->label('Carrier Services')
                                    ->options(fn () => CarrierService::query()
                                        ->where('active', true)
                                        ->with('carrier')
                                        ->get()
                                        ->groupBy(fn ($cs) => $cs->carrier->label())
                                        ->flatMap(fn ($services, $carrier) => $services->mapWithKeys(
                                            fn ($cs): array => [$cs->id => "{$carrier}: {$cs->name}"]
                                        ))
                                        ->toArray())
                                    ->columns(2)
                                    ->columnSpanFull(),
                                Forms\Components\TagsInput::make('aliases')
                                    ->label('Aliases')
                                    ->placeholder('Add alias')
                                    ->helperText('Reference values from your import source that map to this method.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add Shipping Method')
                            ->reorderable(false),
                    ]),
            ])
            ->afterValidation(function (): void {
                $this->saveChannelsAndMethods();
                $this->advanceStep(5);
            });
    }

    private function importSourceStep(): Step
    {
        return Step::make('Order Import')
            ->icon('heroicon-o-arrow-down-tray')
            ->description('Configure where shipments come from')
            ->schema([
                Forms\Components\Select::make('import_source')
                    ->label('Import Orders From')
                    ->options([
                        'none' => 'None (manual entry only)',
                        'database' => 'External Database',
                        'shopify' => 'Shopify',
                        'amazon' => 'Amazon',
                    ])
                    ->default('none')
                    ->live(),

                // Database
                Section::make('Database Connection')
                    ->visible(fn (Get $get): bool => $get('import_source') === 'database')
                    ->schema([
                        Forms\Components\Select::make('db_driver')
                            ->label('Driver')
                            ->options(ImportConnectionConfig::DRIVERS)
                            ->default('mysql')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set(
                                'db_port',
                                ImportConnectionConfig::defaultPort($state),
                            )),
                        Forms\Components\TextInput::make('db_host')
                            ->label('Host')
                            ->default('127.0.0.1')
                            ->required(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('db_driver')))
                            ->visible(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('db_driver'))),
                        Forms\Components\TextInput::make('db_port')
                            ->label('Port')
                            ->default('3306')
                            ->required(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('db_driver')))
                            ->visible(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('db_driver'))),
                        Forms\Components\TextInput::make('db_database')
                            ->label(fn (Get $get): string => $get('db_driver') === 'sqlite' ? 'Database File Path' : 'Database')
                            ->required(),
                        Forms\Components\TextInput::make('db_username')
                            ->label('Username')
                            ->required(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('db_driver')))
                            ->visible(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('db_driver'))),
                        Forms\Components\TextInput::make('db_password')
                            ->label('Password')
                            ->visible(fn (Get $get): bool => ImportConnectionConfig::usesHost($get('db_driver')))
                            ->password()
                            ->revealable(),
                        Forms\Components\Toggle::make('db_ssh_enabled')
                            ->label('Connect via SSH Tunnel')
                            ->live(),
                        Forms\Components\TextInput::make('db_ssh_host')
                            ->label('SSH Host')
                            ->visible(fn (Get $get): mixed => $get('db_ssh_enabled'))
                            ->required(fn (Get $get): mixed => $get('db_ssh_enabled')),
                        Forms\Components\TextInput::make('db_ssh_port')
                            ->label('SSH Port')
                            ->default('22')
                            ->visible(fn (Get $get): mixed => $get('db_ssh_enabled')),
                        Forms\Components\TextInput::make('db_ssh_user')
                            ->label('SSH User')
                            ->visible(fn (Get $get): mixed => $get('db_ssh_enabled'))
                            ->required(fn (Get $get): mixed => $get('db_ssh_enabled')),
                        Forms\Components\TextInput::make('db_ssh_remote_host')
                            ->label('Remote Host')
                            ->helperText('DB host as seen from the SSH server. Leave blank to use the DB host above.')
                            ->visible(fn (Get $get): mixed => $get('db_ssh_enabled')),
                        Forms\Components\TextInput::make('db_ssh_remote_port')
                            ->label('Remote Port')
                            ->helperText('DB port as seen from the SSH server. Leave blank to use the DB port above.')
                            ->visible(fn (Get $get): mixed => $get('db_ssh_enabled')),
                        Forms\Components\Textarea::make('db_ssh_host_key')
                            ->label('SSH Server Host Key')
                            ->helperText('Paste the SSH server host key so PolyBag can verify it is connecting to the correct server. Example: bastion.example.com ssh-ed25519 AAAA...')
                            ->visible(fn (Get $get): mixed => $get('db_ssh_enabled'))
                            ->required(fn (Get $get): mixed => $get('db_ssh_enabled'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('ssh_public_key')
                            ->label('SSH Public Key')
                            ->helperText('Add this to ~/.ssh/authorized_keys on the SSH host. Optionally append permitopen="host:port" to restrict forwarding to a specific server.')
                            ->visible(fn (Get $get): mixed => $get('db_ssh_enabled'))
                            ->columnSpanFull()
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false)
                            ->default(function (): string {
                                $pubKeyPath = storage_path('app/private/ssh/id_ed25519.pub');
                                if (! file_exists($pubKeyPath)) {
                                    return 'SSH key not generated. Run: php artisan app:generate-ssh-key';
                                }

                                return 'restrict,port-forwarding '.trim(file_get_contents($pubKeyPath));
                            }),
                    ])
                    ->columns(2),

                // Shopify
                Section::make('Shopify')
                    ->visible(fn (Get $get): bool => $get('import_source') === 'shopify')
                    ->schema([
                        Forms\Components\TextInput::make('shopify_shop_domain')
                            ->label('Shop Domain')
                            ->placeholder('your-store.myshopify.com')
                            ->helperText('Your Shopify store domain.'),
                        Forms\Components\Select::make('shopify_channel_id')
                            ->label('Channel')
                            ->options(fn () => Channel::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Sales channel assigned to imported shipments. Leave blank to create a "Shopify" channel automatically. Add more channels in the previous step.'),
                        Forms\Components\Placeholder::make('shopify_oauth_hint')
                            ->label('')
                            ->content('Shopify API credentials and OAuth connection can be configured in App Settings after setup.'),
                    ]),

                // Amazon
                Section::make('Amazon')
                    ->visible(fn (Get $get): bool => $get('import_source') === 'amazon')
                    ->schema([
                        Forms\Components\Select::make('amazon_marketplace_id')
                            ->label('Marketplace')
                            ->options(AmazonMarketplace::options())
                            ->default('ATVPDKIKX0DER')
                            ->required()
                            ->searchable()
                            ->helperText('Additional marketplaces are discovered after Amazon OAuth.'),
                        Forms\Components\Select::make('amazon_channel_id')
                            ->label('Channel')
                            ->options(fn () => Channel::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Sales channel assigned to imported shipments. Leave blank to create an "Amazon" channel automatically. Add more channels in the previous step.'),
                        Forms\Components\Placeholder::make('amazon_hint')
                            ->label('')
                            ->content('Amazon SP-API credentials can be configured in App Settings after setup.'),
                    ]),
            ])
            ->afterValidation(function (): void {
                $this->saveImportSource();
                $this->advanceStep(6);
            });
    }

    private function summaryStep(): Step
    {
        return Step::make('Summary')
            ->icon('heroicon-o-check-circle')
            ->description('Review your configuration')
            ->schema([
                Forms\Components\Placeholder::make('summary_company')
                    ->label('Company')
                    ->content(fn () => app(SettingsService::class)->get('company_name', '-')),
                Forms\Components\Placeholder::make('summary_location')
                    ->label('Default Location')
                    ->content(function (): string {
                        $loc = Location::getDefault();

                        return $loc
                            ? "{$loc->address1}, {$loc->city}, {$loc->state_or_province} {$loc->postal_code}"
                            : '-';
                    }),
                Forms\Components\Placeholder::make('summary_carriers')
                    ->label('Active Carriers')
                    ->content(fn () => Carrier::where('active', true)->pluck('name')->join(', ') ?: 'None'),
                Forms\Components\Placeholder::make('summary_services')
                    ->label('Active Services')
                    ->content(fn (): string => CarrierService::where('active', true)->count().' services enabled'),
                Forms\Components\Placeholder::make('summary_box_sizes')
                    ->label('Box Sizes')
                    ->content(fn (): string => BoxSize::count().' configured'),
                Forms\Components\Placeholder::make('summary_channels')
                    ->label('Channels')
                    ->content(fn () => Channel::where('active', true)->pluck('name')->join(', ') ?: 'None'),
                Forms\Components\Placeholder::make('summary_methods')
                    ->label('Shipping Methods')
                    ->content(fn () => ShippingMethod::where('active', true)->pluck('name')->join(', ') ?: 'None'),
                Forms\Components\Placeholder::make('summary_import')
                    ->label('Order Import')
                    ->content(function (): string {
                        $source = DataSource::importing()->first();

                        if (! $source) {
                            return 'None (manual entry only)';
                        }

                        $driverLabel = match ($source->source_type) {
                            DatabaseSource::class => 'Database',
                            ShopifySource::class => 'Shopify',
                            AmazonSource::class => 'Amazon SP-API',
                            default => class_basename($source->source_type),
                        };

                        return "{$source->name} ({$driverLabel})";
                    }),
                Forms\Components\Placeholder::make('summary_next_steps')
                    ->label('Next Steps')
                    ->content(function (): HtmlString {
                        $incomplete = CarrierAccount::with('carrier')
                            ->whereHas('carrier', fn ($q) => $q->where('active', true))
                            ->get()
                            ->filter(fn (CarrierAccount $a): bool => $a->connectionStatus() === 'Needs Setup');

                        $items = $incomplete->map(function (CarrierAccount $account): string {
                            // Escaped: the display name and the account's carrier are operator-edited.
                            $url = e(CarrierAccountResource::getUrl('edit', ['record' => $account->id]));
                            $verb = $account->carrier->name === Carrier::FEDEX ? 'Register' : 'Connect';
                            $carrierLabel = e($account->carrier->label());

                            return "<li><a href=\"{$url}\" class=\"text-primary-600 hover:underline font-medium\">{$verb} {$carrierLabel}</a> — credentials required before shipping</li>";
                        })->values()->all();

                        if ($source = DataSource::importing()->first()) {
                            $url = e(DataSourceResource::getUrl('edit', ['record' => $source->id]));
                            $sourceName = e($source->name);
                            $items[] = "<li><a href=\"{$url}\" class=\"text-primary-600 hover:underline font-medium\">Finish configuring {$sourceName}</a> — credentials, queries, and connection test</li>";
                        }

                        $items[] = '<li class="text-gray-500">Configure your printer &amp; scale in <strong>Device Settings</strong> on each workstation</li>';

                        return new HtmlString('<ul class="list-disc ml-4 space-y-1">'.implode('', $items).'</ul>');
                    }),
            ]);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function carrierSection(string $carrierName, string $key): Section
    {
        return Section::make($carrierName)
            ->schema([
                Forms\Components\Toggle::make("carrier_{$key}_active")
                    ->label("Enable {$carrierName}")
                    ->live(),
                Forms\Components\CheckboxList::make("carrier_{$key}_services")
                    ->label('Services')
                    ->options(fn () => CarrierService::whereHas('carrier', fn ($q) => $q->where('name', $carrierName))
                        ->pluck('name', 'id'))
                    ->visible(fn (Get $get): mixed => $get("carrier_{$key}_active"))
                    ->columns(2),
            ]);
    }

    // ──────────────────────────────────────────────
    // Save Methods
    // ──────────────────────────────────────────────

    private function saveCompanyInfo(): void
    {
        $data = $this->form->getState();
        $settings = app(SettingsService::class);

        $settings->set('company_name', $data['company_name'], group: 'company');

        $locationStateOrProvince = $data['location_state_or_province'] ?? null;

        Location::updateOrCreate(
            ['is_default' => true],
            [
                'name' => $data['location_name'],
                'company' => $data['location_company'],
                'first_name' => $data['location_first_name'],
                'last_name' => $data['location_last_name'],
                'address1' => $data['location_address1'],
                'address2' => $data['location_address2'],
                'city' => $data['location_city'],
                'state_or_province' => $locationStateOrProvince,
                'postal_code' => $data['location_postal_code'],
                'country' => $data['location_country'],
                'phone' => $data['location_phone'],
                'timezone' => $data['location_timezone'],
                'active' => true,
            ]
        );

        Location::clearDefaultCache();
    }

    private function saveCarriers(): void
    {
        $data = $this->form->getState();

        foreach ([Carrier::USPS => 'usps', Carrier::FEDEX => 'fedex', Carrier::UPS => 'ups'] as $name => $key) {
            $active = $data["carrier_{$key}_active"] ?? false;
            $selectedServices = $data["carrier_{$key}_services"] ?? [];

            Carrier::where('name', $name)->update(['active' => $active]);

            if ($active && ! empty($selectedServices)) {
                // Enable selected, disable others for this carrier
                CarrierService::whereHas('carrier', fn ($q) => $q->where('name', $name))
                    ->update(['active' => false]);
                CarrierService::whereIn('id', $selectedServices)
                    ->update(['active' => true]);
            } elseif (! $active) {
                // Disable all services for inactive carrier
                CarrierService::whereHas('carrier', fn ($q) => $q->where('name', $name))
                    ->update(['active' => false]);
            }
        }

        app(CacheService::class)->clearCarrierServicesCache();

        // Create a default CarrierAccount + global (null,null) scope for each
        // newly-enabled carrier so resolveForShipment() can find the account.
        foreach ([Carrier::USPS, Carrier::FEDEX, Carrier::UPS] as $carrierName) {
            $carrier = Carrier::where('name', $carrierName)->first();
            if (! $carrier?->active) {
                continue;
            }

            $account = CarrierAccount::firstOrCreate(
                ['carrier_id' => $carrier->id],
                ['name' => "{$carrierName} Default", 'active' => true],
            );

            if (! $account->scopes()->whereNull('location_id')->whereNull('client_id')->exists()) {
                $account->scopes()->create(['location_id' => null, 'client_id' => null, 'rate_shop' => false]);
            }
        }
    }

    private function saveBoxSizes(): void
    {
        $data = $this->form->getState();
        $boxSizes = $data['box_sizes'] ?? [];

        if ($data['prepopulate_box_sizes'] ?? false) {
            app(BoxSizeSeeder::class)->run();
        }

        foreach ($boxSizes as $box) {
            BoxSize::create([
                'label' => $box['label'],
                'code' => $box['code'],
                'type' => $box['type'],
                'height' => $box['height'],
                'width' => $box['width'],
                'length' => $box['length'],
                'max_weight' => $box['max_weight'],
                'empty_weight' => $box['empty_weight'] ?? 0,
                'carrier_packaging' => $box['carrier_packaging'] ?? null,
            ]);
        }

        if (empty($boxSizes) && ! BoxSize::exists()) {
            Notification::make()
                ->info()
                ->title('No box sizes configured')
                ->body('You can add box sizes later in Settings, or enter dimensions manually when shipping.')
                ->send();
        }
    }

    private function saveChannelsAndMethods(): void
    {
        $data = $this->form->getState();

        foreach ($data['channels'] ?? [] as $channelData) {
            $channel = Channel::create([
                'name' => $channelData['name'],
                'icon' => $channelData['icon'] ?? null,
                'active' => true,
            ]);

            foreach ($channelData['aliases'] ?? [] as $alias) {
                if (! empty($alias)) {
                    $channel->aliases()->create(['reference' => $alias]);
                }
            }
        }

        if ($data['prepopulate_shipping_methods'] ?? false) {
            app(ShippingMethodSeeder::class)->run();
        }

        foreach ($data['shipping_methods'] ?? [] as $methodData) {
            $method = ShippingMethod::create([
                'name' => $methodData['name'],
                'commitment_days' => $methodData['commitment_days'] ?? null,
                'active' => true,
            ]);

            if (! empty($methodData['carrier_services'])) {
                $method->carrierServices()->sync($methodData['carrier_services']);
            }

            foreach ($methodData['aliases'] ?? [] as $alias) {
                if (! empty($alias)) {
                    $method->aliases()->create(['reference' => $alias]);
                }
            }
        }
    }

    private function saveImportSource(): void
    {
        $data = $this->form->getState();
        $source = $data['import_source'] ?? 'none';

        if ($source === 'database') {
            $this->saveDatabaseDataSource($data);
        } elseif ($source === 'shopify') {
            $this->saveShopifyDataSource($data);
        } elseif ($source === 'amazon') {
            $this->saveAmazonDataSource($data);
        } else {
            $this->stopImportingOrders();
        }
    }

    /**
     * "None (manual entry only)" turns order import off on every connection
     * that has it, inactive ones included, so reactivating one later does not
     * quietly resume importing. Each connection keeps its `active` flag, so
     * postage bought through it and tracking written back to it carry on.
     * Saved one at a time so each change is audited.
     */
    private function stopImportingOrders(): void
    {
        DataSource::where('import_enabled', true)->get()->each(
            fn (DataSource $record): bool => $record->update(['import_enabled' => false]),
        );
    }

    /**
     * The connection of this driver the wizard configures: one that already
     * imports orders if there is one, so a postage-only connection of the same
     * driver is not turned into an importer beside it.
     */
    private function importConnectionFor(string $sourceType): DataSource
    {
        return DataSource::query()
            ->orderByDesc('import_enabled')
            ->orderByDesc('active')
            ->orderBy('id')
            ->firstOrNew(['source_type' => $sourceType]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveDatabaseDataSource(array $data): void
    {
        $newSettings = [
            'db_driver' => $data['db_driver'] ?? 'mysql',
            'db_host' => $data['db_host'] ?? '127.0.0.1',
            'db_port' => (int) ($data['db_port'] ?: ImportConnectionConfig::defaultPort($data['db_driver'] ?? 'mysql')),
            'db_database' => $data['db_database'] ?? '',
            'db_username' => $data['db_username'] ?? '',
            'ssh_enabled' => (bool) ($data['db_ssh_enabled'] ?? false),
        ];

        if ($newSettings['ssh_enabled']) {
            $newSettings += [
                'ssh_host' => $data['db_ssh_host'] ?? '',
                'ssh_port' => (int) ($data['db_ssh_port'] ?? 22),
                'ssh_user' => $data['db_ssh_user'] ?? '',
                'ssh_remote_host' => $data['db_ssh_remote_host'] ?? '',
                'ssh_remote_port' => $data['db_ssh_remote_port'] ?? '',
                'ssh_host_key' => $data['db_ssh_host_key'] ?? '',
            ];
        }

        $record = $this->importConnectionFor(DatabaseSource::class);
        $record->fill([
            'name' => $record->name ?? 'Imported Orders Database',
            'active' => true,
            'import_enabled' => true,
            'settings' => array_merge($record->settings ?? [], $newSettings),
        ]);

        if (! empty($data['db_password'])) {
            $record->mergeSecret('db_password', $data['db_password']);
        }

        $record->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveShopifyDataSource(array $data): void
    {
        $record = $this->importConnectionFor(ShopifySource::class);

        $newSettings = [
            'channel_name' => $data['shopify_channel_id']
                ?? $record->settings['channel_name']
                ?? Channel::firstOrCreate(['name' => 'Shopify'], ['active' => true])->id,
        ];

        /**
         * Fulfillment-order import stays off until it is activated through
         * ShopifyFulfillmentOrderActivationService, which verifies the Shopify
         * scopes and location mappings it depends on. See CreateDataSource.
         */
        if (! empty($data['shopify_shop_domain'])) {
            $newSettings['shop_domain'] = $data['shopify_shop_domain'];
        }

        $record->fill([
            'name' => $record->name ?? 'Shopify',
            'active' => true,
            'import_enabled' => true,
            'settings' => array_merge($record->settings ?? [], $newSettings),
        ]);
        $record->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveAmazonDataSource(array $data): void
    {
        $record = $this->importConnectionFor(AmazonSource::class);

        $newSettings = [
            'channel_name' => $data['amazon_channel_id']
                ?? $record->settings['channel_name']
                ?? Channel::firstOrCreate(['name' => 'Amazon'], ['active' => true])->id,
        ];

        if (! empty($data['amazon_marketplace_id'])) {
            $newSettings['marketplace_id'] = $data['amazon_marketplace_id'];
        }

        $record->fill([
            'name' => $record->name ?? 'Amazon',
            'active' => true,
            'import_enabled' => true,
            'settings' => array_merge($record->settings ?? [], $newSettings),
        ]);
        $record->save();
    }

    private function advanceStep(int $next): void
    {
        $settings = app(SettingsService::class);
        $current = (int) $settings->get('setup_wizard_step', 1);

        if ($next > $current) {
            $settings->set('setup_wizard_step', $next, 'integer', group: 'system');
        }
    }

    // ──────────────────────────────────────────────
    // Actions
    // ──────────────────────────────────────────────

    public function skipWizard(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('setup_complete', true, 'boolean', group: 'system');
        $settings->clearCache();

        Notification::make()
            ->info()
            ->title('Setup wizard skipped')
            ->body('You can configure these settings anytime from the Settings menu.')
            ->send();

        $this->redirect('/');
    }

    public function completeSetup(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('setup_complete', true, 'boolean', group: 'system');
        $settings->clearCache();

        $hasIncomplete = CarrierAccount::with('carrier')
            ->whereHas('carrier', fn ($q) => $q->where('active', true))
            ->get()
            ->contains(fn (CarrierAccount $a): bool => $a->connectionStatus() === 'Needs Setup');

        Notification::make()
            ->success()
            ->title('Setup complete!')
            ->body($hasIncomplete
                ? 'Activate your carrier accounts to start shipping.'
                : 'Configure your printer and scale on the Device Settings page when ready.')
            ->send();

        $this->redirect($hasIncomplete ? CarrierAccountResource::getUrl('index') : '/');
    }
}
