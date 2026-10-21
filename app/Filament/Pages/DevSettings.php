<?php

namespace App\Filament\Pages;

use App\Enums\AppMode;
use App\Enums\Role;
use App\Models\Setting;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
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
class DevSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Dev Settings';

    protected static UnitEnum|string|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.dev-settings';

    public static function canAccess(): bool
    {
        return AppMode::current() === AppMode::Dev && auth()->user()?->role->isAtLeast(Role::Admin);
    }

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(SettingsService::class);

        $this->form->fill([
            'sandbox_mode' => $settings->get('sandbox_mode', false),
            'suppress_printing' => $settings->get('suppress_printing', false),
            'address_validation_use_real_in_sandbox' => $settings->get('address_validation_use_real_in_sandbox', false),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Carrier APIs')
                        ->description('Control which carrier API environment is used. These settings only apply in local development.')
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
                            Toggle::make('address_validation_use_real_in_sandbox')
                                ->label('Use Real Address Validation')
                                ->helperText('When enabled, USPS/Google address validation calls are made for real even in sandbox mode (for testing the integration itself). Otherwise sandbox always uses the fake validator.')
                                ->default(false)
                                ->visible(fn (Get $get): bool => (bool) $get('sandbox_mode')),
                        ])
                        ->columns(1),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save Dev Settings')
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
        $useRealAddressValidationInSandbox = $sandboxMode ? (bool) ($data['address_validation_use_real_in_sandbox'] ?? false) : false;
        $previousSandboxMode = (bool) app(SettingsService::class)->get('sandbox_mode', false);

        $settings = [
            'sandbox_mode' => $sandboxMode,
            'suppress_printing' => $suppressPrinting,
            'address_validation_use_real_in_sandbox' => $useRealAddressValidationInSandbox,
        ];

        foreach ($settings as $key => $value) {
            $setting = Setting::find($key);

            if ($setting) {
                $setting->value = $value;
                $setting->save();
            } else {
                Setting::create([
                    'key' => $key,
                    'value' => $value ? '1' : '0',
                    'type' => 'boolean',
                    'group' => 'dev',
                ]);
            }
        }

        app(SettingsService::class)->clearCache();

        // Token caches are namespaced by environment, so each environment keeps its
        // own slot across a flip. This flush is belt-and-braces: it forces a clean
        // re-auth rather than protecting against a cross-environment token.
        if ($sandboxMode !== $previousSandboxMode) {
            Cache::forget('usps_authenticator');
            Cache::forget('usps_payment_authorization_token:global');
            Cache::forget('fedex_authenticator');
            Cache::forget('fedex_authenticator_sandbox');
            Cache::forget('ups_authenticator');
            Cache::forget('ups_oauth_token');
        }

        $this->rememberData();

        Notification::make()
            ->success()
            ->title('Dev settings saved')
            ->send();
    }
}
