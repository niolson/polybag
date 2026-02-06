<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Setting;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
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

    public function mount(): void
    {
        $this->form->fill([
            'company_name' => SettingsService::get('company_name', ''),
            'from_address_first_name' => SettingsService::get('from_address.first_name', ''),
            'from_address_last_name' => SettingsService::get('from_address.last_name', ''),
            'from_address_company' => SettingsService::get('from_address.company', ''),
            'from_address_street' => SettingsService::get('from_address.street', ''),
            'from_address_street2' => SettingsService::get('from_address.street2', ''),
            'from_address_city' => SettingsService::get('from_address.city', ''),
            'from_address_state' => SettingsService::get('from_address.state', ''),
            'from_address_zip' => SettingsService::get('from_address.zip', ''),
            'from_address_phone' => SettingsService::get('from_address.phone', ''),
            'packing_validation_enabled' => SettingsService::get('packing_validation_enabled', true),
            'transparency_enabled' => SettingsService::get('transparency_enabled', true),
            'sandbox_mode' => SettingsService::get('sandbox_mode', false),
            'suppress_printing' => SettingsService::get('suppress_printing', false),
        ]);
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

                    Section::make('From Address')
                        ->description('Return address used on shipping labels')
                        ->schema([
                            TextInput::make('from_address_first_name')
                                ->label('First Name')
                                ->required()
                                ->maxLength(50),
                            TextInput::make('from_address_last_name')
                                ->label('Last Name')
                                ->required()
                                ->maxLength(50),
                            TextInput::make('from_address_company')
                                ->label('Company')
                                ->maxLength(100),
                            TextInput::make('from_address_street')
                                ->label('Street Address')
                                ->required()
                                ->maxLength(100),
                            TextInput::make('from_address_street2')
                                ->label('Street Address 2')
                                ->maxLength(100),
                            TextInput::make('from_address_city')
                                ->label('City')
                                ->required()
                                ->maxLength(50),
                            TextInput::make('from_address_state')
                                ->label('State')
                                ->required()
                                ->maxLength(2)
                                ->placeholder('WA'),
                            TextInput::make('from_address_zip')
                                ->label('ZIP Code')
                                ->required()
                                ->maxLength(10)
                                ->placeholder('98072'),
                            TextInput::make('from_address_phone')
                                ->label('Phone')
                                ->tel()
                                ->maxLength(20),
                        ])
                        ->columns(2),

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
                        ])
                        ->columns(1),

                    Section::make('Testing')
                        ->description('Sandbox and testing settings')
                        ->schema([
                            Toggle::make('sandbox_mode')
                                ->label('Sandbox Mode')
                                ->helperText('When enabled, USPS and FedEx API calls use sandbox/test URLs instead of production.')
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
        $previousSandboxMode = (bool) SettingsService::get('sandbox_mode', false);

        // Map form fields to setting keys
        $settings = [
            'company_name' => $data['company_name'] ?? '',
            'from_address.first_name' => $data['from_address_first_name'] ?? '',
            'from_address.last_name' => $data['from_address_last_name'] ?? '',
            'from_address.company' => $data['from_address_company'] ?? '',
            'from_address.street' => $data['from_address_street'] ?? '',
            'from_address.street2' => $data['from_address_street2'] ?? '',
            'from_address.city' => $data['from_address_city'] ?? '',
            'from_address.state' => $data['from_address_state'] ?? '',
            'from_address.zip' => $data['from_address_zip'] ?? '',
            'from_address.phone' => $data['from_address_phone'] ?? '',
            'packing_validation_enabled' => $data['packing_validation_enabled'] ?? true,
            'transparency_enabled' => $data['transparency_enabled'] ?? true,
            'sandbox_mode' => $sandboxMode,
            'suppress_printing' => $suppressPrinting,
        ];

        // Update each setting
        foreach ($settings as $key => $value) {
            $setting = Setting::find($key);

            if ($setting) {
                $setting->value = $value;
                $setting->save();
            } else {
                // Create if doesn't exist
                $type = is_bool($value) ? 'boolean' : 'string';
                $group = str_contains($key, '.') ? explode('.', $key)[0] : 'general';

                Setting::create([
                    'key' => $key,
                    'value' => is_bool($value) ? ($value ? '1' : '0') : $value,
                    'type' => $type,
                    'group' => $group,
                ]);
            }
        }

        SettingsService::clearCache();

        // Clear cached OAuth tokens when sandbox mode changes so stale tokens aren't reused
        if ($sandboxMode !== $previousSandboxMode) {
            Cache::forget('usps_authenticator');
            Cache::forget('usps_payment_authorization_token');
            Cache::forget('fedex_authenticator');
        }

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }
}
