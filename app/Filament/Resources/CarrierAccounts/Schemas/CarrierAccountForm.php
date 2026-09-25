<?php

namespace App\Filament\Resources\CarrierAccounts\Schemas;

use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Client;
use App\Models\Location;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\UspsAdapter;
use App\Services\OAuthService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class CarrierAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account Details')
                    ->schema([
                        Select::make('carrier_id')
                            ->label('Carrier')
                            // Only carriers whose integration keeps its account here. Shopify
                            // and Amazon postage is bought through a connection (ADR-0002,
                            // 2026-09-22 amendment). An existing record still shows its own
                            // carrier.
                            ->options(fn (string $operation) => Carrier::active()
                                ->when($operation !== 'edit', fn ($query) => $query->whereIn('name', CarrierRegistry::carrierAccountCarrierNames()))
                                ->get()
                                ->mapWithKeys(fn (Carrier $carrier): array => [$carrier->id => $carrier->label()]))
                            ->required()
                            // Fixed once saved. The credentials below are issued
                            // by this carrier and mean nothing to another one, so
                            // moving an account across carriers leaves it unable
                            // to authenticate — and silently re-points the scopes
                            // that route shipments to it. Delete the account and
                            // create the right one instead.
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'The carrier cannot be changed after an account is created. Create a new account for a different carrier.'
                                : null)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                if (! $state || filled($get('name'))) {
                                    return;
                                }

                                $carrier = Carrier::find($state);

                                if ($carrier && CarrierAccount::where('carrier_id', $state)->doesntExist()) {
                                    $set('name', $carrier->label().' Default');
                                }
                            }),
                        TextInput::make('name')
                            ->label('Account Name')
                            ->helperText('A label to identify this account, e.g. "3PL USPS" or "Client A FedEx".')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('active')
                            ->default(true),
                    ]),

                // ── USPS ────────────────────────────────────────────────────────────

                Section::make('USPS Credentials')
                    ->description('Overrides the global USPS credentials for shipments assigned to this account.')
                    ->schema([
                        TextEntry::make('usps_oauth_status')
                            ->label('OAuth Status')
                            ->state(fn (?CarrierAccount $record): HtmlString => static::renderAccountOauthStatus($record))
                            ->columnSpanFull(),
                        TextEntry::make('usps_pricing_tier')
                            ->label('Pricing Tier')
                            ->state(fn (?CarrierAccount $record): HtmlString => static::renderUspsPricingTier($record))
                            ->helperText('Use the "Test USPS Connection" action above to check whether this account has negotiated (CONTRACT) rates.')
                            ->columnSpanFull(),
                        TextInput::make('credentials.crid')
                            ->label('CRID')
                            ->helperText('Customer Registration ID.')
                            ->maxLength(50),
                        TextInput::make('credentials.mid')
                            ->label('MID')
                            ->helperText('Mailer ID (9 digits).')
                            ->maxLength(9),
                        TextInput::make('credentials.eps_account')
                            ->label('EPS Account')
                            ->helperText('Leave blank to auto-populate from the OAuth token.')
                            ->maxLength(50),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::USPS)
                    ->columns(2)
                    ->collapsible(),

                Section::make('Advanced / API App Credentials')
                    ->description('Your own USPS developer app credentials. Hosted installs normally leave these empty and use the shared OAuth connection; self-hosted installs fill them in — see docs/self-hosting.md.')
                    ->schema([
                        TextInput::make('usps_adv_client_id')
                            ->label('Client ID')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('client_id')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                        TextInput::make('usps_adv_client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('client_secret')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::USPS)
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),

                // ── FedEx ───────────────────────────────────────────────────────────

                Section::make('FedEx Account')
                    ->description('FedEx account connection. Use the "Connect FedEx Account" action above to provision credentials.')
                    ->schema([
                        TextEntry::make('fedex_connection_status')
                            ->label('Connection Status')
                            ->state(fn (?CarrierAccount $record): HtmlString => $record?->connectionStatus() === 'Connected'
                                ? new HtmlString('<span class="text-success-600 dark:text-success-400 font-medium">Connected</span> — credentials configured for the active environment')
                                : new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not connected</span> — configure credentials for the active environment'))
                            ->columnSpanFull(),
                        TextInput::make('fedex_production_account_number')
                            ->label('Production Account Number')
                            ->maxLength(50)
                            ->readOnly(fn (?CarrierAccount $record): bool => $record !== null
                                && filled($record->secret('child_key'))
                                && ($record->credential('child_env') ?? 'production') === 'production')
                            ->afterStateHydrated(fn ($component, ?CarrierAccount $record) => $component->state($record?->fedexAccountNumber('production'))),
                        TextInput::make('fedex_sandbox_account_number')
                            ->label('Sandbox Account Number')
                            ->helperText('Use the test account number assigned with your FedEx sandbox API project.')
                            ->maxLength(50)
                            ->readOnly(fn (?CarrierAccount $record): bool => $record !== null
                                && filled($record->secret('child_key'))
                                && $record->credential('child_env') === 'sandbox')
                            ->afterStateHydrated(fn ($component, ?CarrierAccount $record) => $component->state($record?->fedexAccountNumber('sandbox'))),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::FEDEX)
                    ->columns(2)
                    ->collapsible(),

                Section::make('Advanced / API App Credentials')
                    ->description('Your own FedEx developer app credentials. Hosted installs normally leave these empty and use the shared broker credentials; self-hosted installs fill them in — see docs/self-hosting.md.')
                    ->schema([
                        TextInput::make('fedex_adv_api_key')
                            ->label('Production API Key')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('api_key')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                        TextInput::make('fedex_adv_api_secret')
                            ->label('Production API Secret')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('api_secret')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                        TextInput::make('fedex_adv_sandbox_api_key')
                            ->label('Sandbox API Key')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('sandbox_api_key')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                        TextInput::make('fedex_adv_sandbox_api_secret')
                            ->label('Sandbox API Secret')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('sandbox_api_secret')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::FEDEX)
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),

                // ── Location Assignments ─────────────────────────────────────────────

                Section::make('Assignments')
                    ->description('Scope this account to specific locations and/or clients. A global assignment (no restrictions) is created automatically when the account is saved.')
                    ->schema([
                        Repeater::make('scopes')
                            ->relationship()
                            ->schema([
                                Select::make('location_id')
                                    ->label('Location')
                                    ->options(fn () => Location::active()->pluck('name', 'id'))
                                    ->placeholder('All locations')
                                    ->nullable()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->visible(fn () => app(SettingsService::class)->get('multi_location_enabled', false)),
                                Select::make('client_id')
                                    ->label('Client')
                                    ->options(fn () => Client::where('active', true)->pluck('name', 'id'))
                                    ->placeholder('All clients')
                                    ->nullable()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->visible(fn () => app(SettingsService::class)->get('multi_client_enabled', false)),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Add Assignment')
                            ->columnSpanFull(),
                    ])
                    ->hidden(fn (?CarrierAccount $record): bool => ! $record?->exists
                        || (! app(SettingsService::class)->get('multi_location_enabled', false)
                            && ! app(SettingsService::class)->get('multi_client_enabled', false)))
                    ->collapsible(),

                // ── UPS ─────────────────────────────────────────────────────────────

                Section::make('UPS Account')
                    ->description('UPS account connection. Use the "Connect UPS" action above to authorize via OAuth.')
                    ->schema([
                        TextEntry::make('ups_oauth_status')
                            ->label('OAuth Status')
                            ->state(fn (?CarrierAccount $record): HtmlString => static::renderAccountOauthStatus($record))
                            ->columnSpanFull(),
                        TextInput::make('ups_account_number')
                            ->label('Account Number')
                            ->maxLength(50)
                            ->afterStateHydrated(fn ($component, ?CarrierAccount $record) => $component->state($record?->credential('account_number'))),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::UPS)
                    ->collapsible(),

                Section::make('Advanced / API App Credentials')
                    ->description('Your own UPS developer app credentials. Hosted installs normally leave these empty and use the shared OAuth connection; self-hosted installs fill them in — see docs/self-hosting.md.')
                    ->schema([
                        TextInput::make('ups_adv_client_id')
                            ->label('Client ID')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('client_id')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                        TextInput::make('ups_adv_client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('client_secret')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::UPS)
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    private static function renderAccountOauthStatus(?CarrierAccount $record): HtmlString
    {
        if (! $record?->exists) {
            return new HtmlString('<span class="text-gray-400 dark:text-gray-500">Save the account first, then connect via OAuth.</span>');
        }

        if (app(OAuthService::class)->isAccountConnected($record)) {
            $connectedAt = $record->credential('oauth_connected_at');
            $time = $connectedAt ? Carbon::parse($connectedAt)->diffForHumans() : null;

            return new HtmlString(
                '<span class="text-success-600 dark:text-success-400 font-medium">Connected</span>'
                    .($time ? " — {$time}" : '')
            );
        }

        return new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not connected</span>');
    }

    private static function renderUspsPricingTier(?CarrierAccount $record): HtmlString
    {
        if (! $record?->exists) {
            return new HtmlString('<span class="text-gray-400 dark:text-gray-500">Save and test the account to detect its pricing tier.</span>');
        }

        return match (app(UspsAdapter::class)->cachedPricingType($record)) {
            'CONTRACT' => new HtmlString('<span class="text-success-600 dark:text-success-400 font-medium">CONTRACT</span> — negotiated rates'),
            'RETAIL' => new HtmlString('<span class="text-warning-600 dark:text-warning-400 font-medium">RETAIL</span> — standard rates (no EPS contract access)'),
            default => new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not tested yet</span>'),
        };
    }
}
