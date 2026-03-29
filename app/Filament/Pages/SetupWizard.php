<?php

namespace App\Filament\Pages;

use App\Enums\BoxSizeType;
use App\Enums\FedexPackageType;
use App\Enums\Role;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Location;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;

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
            'location_state' => $location?->state_or_province,
            'location_postal_code' => $location?->postal_code,
            'location_country' => $location?->country ?? 'US',
            'location_phone' => $location?->phone,
            'location_timezone' => $location?->timezone ?? 'America/New_York',

            // Step 2: Carriers
            'carrier_usps_active' => Carrier::where('name', 'USPS')->value('active') ?? false,
            'carrier_usps_services' => CarrierService::whereHas('carrier', fn ($q) => $q->where('name', 'USPS'))->where('active', true)->pluck('id')->toArray(),
            'carrier_fedex_active' => Carrier::where('name', 'FedEx')->value('active') ?? false,
            'carrier_fedex_services' => CarrierService::whereHas('carrier', fn ($q) => $q->where('name', 'FedEx'))->where('active', true)->pluck('id')->toArray(),
            'carrier_ups_active' => Carrier::where('name', 'UPS')->value('active') ?? false,
            'carrier_ups_services' => CarrierService::whereHas('carrier', fn ($q) => $q->where('name', 'UPS'))->where('active', true)->pluck('id')->toArray(),

            // Step 3: Box sizes (repeater is for new additions only)
            'box_sizes' => [],

            // Step 4: Channels & shipping methods (repeaters are for new additions only)
            'channels' => [],
            'shipping_methods' => [],

            // Step 5: Import source
            'import_source' => 'none',
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
                    ->cancelAction(new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render(<<<'BLADE'
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
                    ->submitAction(new \Illuminate\Support\HtmlString(\Illuminate\Support\Facades\Blade::render(<<<'BLADE'
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
                        Forms\Components\TextInput::make('location_company')
                            ->label('Company')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location_first_name')
                            ->label('First Name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location_last_name')
                            ->label('Last Name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location_address1')
                            ->label('Street Address')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location_address2')
                            ->label('Street Address 2')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location_city')
                            ->label('City')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location_state')
                            ->label('State / Province')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('location_postal_code')
                            ->label('Postal Code')
                            ->required()
                            ->maxLength(20),
                        Forms\Components\TextInput::make('location_country')
                            ->label('Country')
                            ->required()
                            ->maxLength(2)
                            ->default('US'),
                        Forms\Components\TextInput::make('location_phone')
                            ->label('Phone')
                            ->tel(),
                        Forms\Components\Select::make('location_timezone')
                            ->label('Timezone')
                            ->options(fn () => collect(timezone_identifiers_list())
                                ->filter(fn ($tz) => str_starts_with($tz, 'America/') || str_starts_with($tz, 'Pacific/') || str_starts_with($tz, 'US/'))
                                ->mapWithKeys(fn ($tz) => [$tz => str_replace('_', ' ', $tz)]))
                            ->searchable()
                            ->default('America/New_York')
                            ->required(),
                    ])
                    ->columns(2),
            ])
            ->afterValidation(function () {
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
                $this->carrierSection('USPS', 'usps'),
                $this->carrierSection('FedEx', 'fedex'),
                $this->carrierSection('UPS', 'ups'),
            ])
            ->afterValidation(function () {
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
                Forms\Components\Placeholder::make('box_size_notice')
                    ->label('')
                    ->content('Box sizes speed up the packing workflow by pre-filling dimensions. You can skip this step and enter dimensions manually when shipping, or add box sizes later.'),
                Forms\Components\Placeholder::make('existing_box_sizes')
                    ->label('Existing Box Sizes')
                    ->visible(fn () => BoxSize::exists())
                    ->content(function () {
                        $boxes = BoxSize::all();
                        $html = '<table class="w-full text-sm"><thead class="text-left text-gray-500 dark:text-gray-400 border-b"><tr>'
                            .'<th class="pb-2 pr-4">Label</th><th class="pb-2 pr-4">Code</th><th class="pb-2 pr-4">Type</th>'
                            .'<th class="pb-2 pr-4">Dimensions</th><th class="pb-2">Max Weight</th></tr></thead><tbody>';
                        foreach ($boxes as $box) {
                            $dims = "{$box->length} x {$box->width} x {$box->height} in";
                            $html .= "<tr class=\"border-b border-gray-100 dark:border-gray-700\">"
                                ."<td class=\"py-2 pr-4\">{$box->label}</td>"
                                ."<td class=\"py-2 pr-4 font-mono text-xs\">{$box->code}</td>"
                                ."<td class=\"py-2 pr-4\">{$box->type->getLabel()}</td>"
                                ."<td class=\"py-2 pr-4\">{$dims}</td>"
                                ."<td class=\"py-2\">{$box->max_weight} lbs</td></tr>";
                        }
                        $html .= '</tbody></table>';

                        return new \Illuminate\Support\HtmlString($html);
                    }),
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
                        Forms\Components\Select::make('fedex_package_type')
                            ->label('FedEx Package Type')
                            ->options(FedexPackageType::class)
                            ->nullable(),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Add Box Size')
                    ->reorderable(false),
            ])
            ->afterValidation(function () {
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
                            ->content(function () {
                                $channels = Channel::with('aliases')->get();
                                $html = '<table class="w-full text-sm"><thead class="text-left text-gray-500 dark:text-gray-400 border-b"><tr>'
                                    .'<th class="pb-2 pr-4">Name</th><th class="pb-2">Aliases</th></tr></thead><tbody>';
                                foreach ($channels as $ch) {
                                    $aliases = $ch->aliases->pluck('reference')->join(', ') ?: '-';
                                    $html .= "<tr class=\"border-b border-gray-100 dark:border-gray-700\">"
                                        ."<td class=\"py-2 pr-4\">{$ch->name}</td>"
                                        ."<td class=\"py-2 text-gray-500\">{$aliases}</td></tr>";
                                }
                                $html .= '</tbody></table>';

                                return new \Illuminate\Support\HtmlString($html);
                            }),
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
                                    ])->mapWithKeys(fn (string $label, string $icon) => [
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
                        Forms\Components\Placeholder::make('existing_methods')
                            ->label('Existing Shipping Methods')
                            ->visible(fn () => ShippingMethod::exists())
                            ->content(function () {
                                $methods = ShippingMethod::with(['aliases', 'carrierServices'])->get();
                                $html = '<table class="w-full text-sm"><thead class="text-left text-gray-500 dark:text-gray-400 border-b"><tr>'
                                    .'<th class="pb-2 pr-4">Name</th><th class="pb-2 pr-4">Commitment</th><th class="pb-2 pr-4">Carrier Services</th><th class="pb-2">Aliases</th></tr></thead><tbody>';
                                foreach ($methods as $m) {
                                    $aliases = $m->aliases->pluck('reference')->join(', ') ?: '-';
                                    $services = $m->carrierServices->map(fn ($cs) => $cs->carrier->name . ': ' . $cs->name)->join(', ') ?: '-';
                                    $days = $m->commitment_days ? "{$m->commitment_days} days" : '-';
                                    $html .= "<tr class=\"border-b border-gray-100 dark:border-gray-700\">"
                                        ."<td class=\"py-2 pr-4\">{$m->name}</td>"
                                        ."<td class=\"py-2 pr-4\">{$days}</td>"
                                        ."<td class=\"py-2 pr-4 text-gray-500\">{$services}</td>"
                                        ."<td class=\"py-2 text-gray-500\">{$aliases}</td></tr>";
                                }
                                $html .= '</tbody></table>';

                                return new \Illuminate\Support\HtmlString($html);
                            }),
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
                                Forms\Components\Toggle::make('saturday_delivery')
                                    ->label('Saturday Delivery'),
                                Forms\Components\CheckboxList::make('carrier_services')
                                    ->label('Carrier Services')
                                    ->options(fn () => CarrierService::query()
                                        ->where('active', true)
                                        ->with('carrier')
                                        ->get()
                                        ->groupBy(fn ($cs) => $cs->carrier->name)
                                        ->flatMap(fn ($services, $carrier) => $services->mapWithKeys(
                                            fn ($cs) => [$cs->id => "{$carrier}: {$cs->name}"]
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
            ->afterValidation(function () {
                $this->saveChannelsAndMethods();
                $this->advanceStep(5);
            });
    }

    private function importSourceStep(): Step
    {
        return Step::make('Import Source')
            ->icon('heroicon-o-arrow-down-tray')
            ->description('Configure where shipments come from')
            ->schema([
                Forms\Components\Select::make('import_source')
                    ->label('Import Source')
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
                    ->visible(fn (Get $get) => $get('import_source') === 'database')
                    ->schema([
                        Forms\Components\Select::make('db_driver')
                            ->label('Driver')
                            ->options([
                                'mysql' => 'MySQL / MariaDB',
                                'pgsql' => 'PostgreSQL',
                                'sqlsrv' => 'SQL Server',
                                'sqlite' => 'SQLite',
                            ])
                            ->default('mysql')
                            ->required(),
                        Forms\Components\TextInput::make('db_host')
                            ->label('Host')
                            ->default('127.0.0.1')
                            ->required(),
                        Forms\Components\TextInput::make('db_port')
                            ->label('Port')
                            ->default('3306')
                            ->required(),
                        Forms\Components\TextInput::make('db_database')
                            ->label('Database')
                            ->required(),
                        Forms\Components\TextInput::make('db_username')
                            ->label('Username')
                            ->required(),
                        Forms\Components\TextInput::make('db_password')
                            ->label('Password')
                            ->password()
                            ->revealable(),
                        Forms\Components\Toggle::make('db_ssh_enabled')
                            ->label('Connect via SSH Tunnel')
                            ->live(),
                        Forms\Components\TextInput::make('db_ssh_host')
                            ->label('SSH Host')
                            ->visible(fn (Get $get) => $get('db_ssh_enabled'))
                            ->required(fn (Get $get) => $get('db_ssh_enabled')),
                        Forms\Components\TextInput::make('db_ssh_port')
                            ->label('SSH Port')
                            ->default('22')
                            ->visible(fn (Get $get) => $get('db_ssh_enabled')),
                        Forms\Components\TextInput::make('db_ssh_user')
                            ->label('SSH User')
                            ->visible(fn (Get $get) => $get('db_ssh_enabled'))
                            ->required(fn (Get $get) => $get('db_ssh_enabled')),
                        Forms\Components\TextInput::make('db_ssh_key')
                            ->label('SSH Key Path')
                            ->visible(fn (Get $get) => $get('db_ssh_enabled'))
                            ->required(fn (Get $get) => $get('db_ssh_enabled'))
                            ->placeholder('/opt/ssh-keys/customer-name'),
                        Forms\Components\Placeholder::make('db_env_note')
                            ->label('')
                            ->content('These settings are saved to the database. You will also need to set the corresponding SHIPMENT_IMPORT_DB_* environment variables in your .env file for the database connection to work.'),
                    ])
                    ->columns(2),

                // Shopify
                Section::make('Shopify')
                    ->visible(fn (Get $get) => $get('import_source') === 'shopify')
                    ->schema([
                        Forms\Components\TextInput::make('shopify_shop_domain')
                            ->label('Shop Domain')
                            ->placeholder('your-store.myshopify.com')
                            ->helperText('Your Shopify store domain.'),
                        Forms\Components\Placeholder::make('shopify_oauth_hint')
                            ->label('')
                            ->content('Shopify API credentials and OAuth connection can be configured in App Settings after setup.'),
                    ]),

                // Amazon
                Section::make('Amazon')
                    ->visible(fn (Get $get) => $get('import_source') === 'amazon')
                    ->schema([
                        Forms\Components\TextInput::make('amazon_marketplace_id')
                            ->label('Marketplace ID')
                            ->default('ATVPDKIKX0DER')
                            ->helperText('US marketplace: ATVPDKIKX0DER'),
                        Forms\Components\Placeholder::make('amazon_hint')
                            ->label('')
                            ->content('Amazon SP-API credentials can be configured in App Settings after setup.'),
                    ]),
            ])
            ->afterValidation(function () {
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
                    ->content(function () {
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
                    ->content(fn () => CarrierService::where('active', true)->count().' services enabled'),
                Forms\Components\Placeholder::make('summary_box_sizes')
                    ->label('Box Sizes')
                    ->content(fn () => BoxSize::count().' configured'),
                Forms\Components\Placeholder::make('summary_channels')
                    ->label('Channels')
                    ->content(fn () => Channel::where('active', true)->pluck('name')->join(', ') ?: 'None'),
                Forms\Components\Placeholder::make('summary_methods')
                    ->label('Shipping Methods')
                    ->content(fn () => ShippingMethod::where('active', true)->pluck('name')->join(', ') ?: 'None'),
                Forms\Components\Placeholder::make('summary_import')
                    ->label('Import Source')
                    ->content(fn () => app(SettingsService::class)->get('import_source', 'none')),
                Forms\Components\Placeholder::make('summary_next')
                    ->label('')
                    ->content('Click "Complete Setup" to finish. Printer and scale can be configured on the Device Settings page.'),
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
                    ->visible(fn (Get $get) => $get("carrier_{$key}_active"))
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
                'state_or_province' => $data['location_state'],
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

        foreach (['USPS' => 'usps', 'FedEx' => 'fedex', 'UPS' => 'ups'] as $name => $key) {
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

        app(\App\Services\CacheService::class)->clearCarrierServicesCache();
    }

    private function saveBoxSizes(): void
    {
        $data = $this->form->getState();
        $boxSizes = $data['box_sizes'] ?? [];

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
                'fedex_package_type' => $box['fedex_package_type'] ?? null,
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

        foreach ($data['shipping_methods'] ?? [] as $methodData) {
            $method = ShippingMethod::create([
                'name' => $methodData['name'],
                'commitment_days' => $methodData['commitment_days'] ?? null,
                'saturday_delivery' => $methodData['saturday_delivery'] ?? false,
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
        $settings = app(SettingsService::class);
        $source = $data['import_source'] ?? 'none';

        $settings->set('import_source', $source, group: 'system');

        if ($source === 'database') {
            $settings->set('import_db_driver', $data['db_driver'] ?? 'mysql', group: 'import');
            $settings->set('import_db_host', $data['db_host'] ?? '127.0.0.1', group: 'import');
            $settings->set('import_db_port', $data['db_port'] ?? '3306', group: 'import');
            $settings->set('import_db_database', $data['db_database'] ?? '', group: 'import');
            $settings->set('import_db_username', $data['db_username'] ?? '', group: 'import');
            if (! empty($data['db_password'])) {
                $settings->set('import_db_password', $data['db_password'], 'string', encrypted: true, group: 'import');
            }
            $settings->set('import_ssh_enabled', $data['db_ssh_enabled'] ?? false, 'boolean', group: 'import');
            if ($data['db_ssh_enabled'] ?? false) {
                $settings->set('import_ssh_host', $data['db_ssh_host'] ?? '', group: 'import');
                $settings->set('import_ssh_port', $data['db_ssh_port'] ?? '22', group: 'import');
                $settings->set('import_ssh_user', $data['db_ssh_user'] ?? '', group: 'import');
                $settings->set('import_ssh_key', $data['db_ssh_key'] ?? '', group: 'import');
            }
        } elseif ($source === 'shopify') {
            if (! empty($data['shopify_shop_domain'])) {
                $settings->set('shopify.shop_domain', $data['shopify_shop_domain'], group: 'shopify');
            }
        } elseif ($source === 'amazon') {
            if (! empty($data['amazon_marketplace_id'])) {
                $settings->set('amazon.marketplace_id', $data['amazon_marketplace_id'], group: 'amazon');
            }
        }

        $settings->clearCache();
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

        Notification::make()
            ->success()
            ->title('Setup complete!')
            ->body('Configure your printer and scale on the Device Settings page when ready.')
            ->send();

        $this->redirect('/');
    }
}
