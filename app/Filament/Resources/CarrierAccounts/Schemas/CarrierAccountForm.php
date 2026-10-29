<?php

namespace App\Filament\Resources\CarrierAccounts\Schemas;

use App\Filament\Resources\DataSources\DataSourceResource;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Client;
use App\Models\Location;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\UspsAdapter;
use App\Services\CredentialFieldVisibility;
use App\Services\OAuthService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Closure;
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
    /**
     * Whether the chosen carrier's account is a connection, so has no
     * carrier account to save.
     */
    private static function takesConnection(mixed $carrierId): bool
    {
        $name = filled($carrierId) ? Carrier::whereKey($carrierId)->value('name') : null;

        return is_string($name) && CarrierRegistry::takesConnection($name);
    }

    private static function fields(): CredentialFieldVisibility
    {
        return app(CredentialFieldVisibility::class);
    }

    /**
     * What an "Advanced / API App Credentials" section says. Where the broker's OAuth
     * cannot connect the account, as in Demo, these are the only way in.
     */
    private static function appCredentialsDescription(string $carrierName): string
    {
        if (! self::fields()->offersCarrierOAuth($carrierName)) {
            return "This account signs in with a {$carrierName} developer app's client ID and secret. The hosted OAuth connection issues production credentials only, so it cannot be used against the sandbox.";
        }

        return "Your own {$carrierName} developer app credentials. Hosted installs normally leave these empty and use the shared OAuth connection; self-hosted installs fill them in — see docs/self-hosting.md.";
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account Details')
                    ->schema([
                        Select::make('carrier_id')
                            ->label('Carrier')
                            // Only carriers whose integration keeps its account here, plus
                            // those whose account is a connection, which point to
                            // Connections instead of saving (`carrier-catalog-reset/15`).
                            // Shopify and Amazon Buy Shipping postage is bought through a
                            // connection too (ADR-0002, 2026-09-22 amendment). An existing
                            // record still shows its own carrier.
                            ->options(fn (string $operation) => Carrier::active()
                                ->when($operation !== 'edit', fn ($query) => $query->whereIn('name', [
                                    ...CarrierRegistry::carrierAccountCarrierNames(),
                                    ...CarrierRegistry::connectionCarrierNames(),
                                ]))
                                ->get()
                                ->mapWithKeys(fn (Carrier $carrier): array => [$carrier->id => $carrier->label()]))
                            ->required()
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (self::takesConnection($value)) {
                                    $fail(Carrier::find($value)?->label().'\'s account is an Amazon connection. Set it up under Integrations → Connections.');
                                }
                            })
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

                Section::make('Account on a Connection')
                    ->schema([
                        TextEntry::make('connection_account_notice')
                            ->hiddenLabel()
                            ->state(fn (Get $get): HtmlString => new HtmlString(
                                e(Carrier::find($get('carrier_id'))?->label() ?? 'This carrier')
                                .' is bought through an Amazon connection, not a carrier account. Opt a connection into selling it under '
                                .'<a href="'.e(DataSourceResource::getUrl('index')).'" class="text-primary-600 underline">Integrations → Connections</a>.'
                            ))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Get $get): bool => self::takesConnection($get('carrier_id'))),

                // ── USPS ────────────────────────────────────────────────────────────

                Section::make('USPS Credentials')
                    ->description('The USPS identifiers labels bought on this account are billed and mailed under. The account signs in through OAuth, or with its own client ID and secret under Advanced / API App Credentials.')
                    ->schema([
                        TextEntry::make('usps_oauth_status')
                            ->label('OAuth Status')
                            ->state(fn (?CarrierAccount $record, Get $get): HtmlString => static::renderAccountOauthStatus($record, Carrier::USPS, $get('usps_adv_client_id')))
                            ->visible(fn (): bool => self::fields()->offersCarrierOAuth(Carrier::USPS))
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

                Section::make('Prepaid Duties (DDP)')
                    ->description('Prepaying duties and taxes bills this account at purchase. USPS makes sending the flag an agreement to its provider\'s terms, so an Admin records the acceptance here.')
                    ->schema([
                        TextEntry::make('usps_ddp_terms_status')
                            ->label('DDP Terms')
                            ->state(fn (?CarrierAccount $record): HtmlString => static::renderDdpTermsStatus($record))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::USPS)
                    ->collapsible(),

                Section::make('Advanced / API App Credentials')
                    ->description(fn (): string => self::appCredentialsDescription(Carrier::USPS))
                    ->schema([
                        TextInput::make('usps_adv_client_id')
                            ->label('Client ID')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('client_id')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->live(onBlur: true),
                        TextInput::make('usps_adv_client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('client_secret')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::USPS
                        && self::fields()->showsCarrierAppCredentials(Carrier::USPS))
                    ->columns(2)
                    ->collapsed(fn (): bool => self::fields()->offersCarrierOAuth(Carrier::USPS))
                    ->collapsible(),

                // ── FedEx ───────────────────────────────────────────────────────────

                Section::make('FedEx Account')
                    ->description('FedEx account connection. Use the "Connect FedEx Account" action above to provision credentials.')
                    ->schema([
                        TextEntry::make('fedex_connection_status')
                            ->label('Connection Status')
                            ->state(fn (?CarrierAccount $record): HtmlString => match (true) {
                                ! $record?->exists && app(OAuthService::class)->opensFedexRegistrationForNewAccount(Carrier::FEDEX) => new HtmlString('<span class="text-gray-400 dark:text-gray-500">After you create this account the Connect FedEx Account wizard opens, to verify it with FedEx and provision its credentials.</span>'),
                                $record?->connectionStatus() === 'Connected' => new HtmlString('<span class="text-success-600 dark:text-success-400 font-medium">Connected</span> — credentials configured for the active environment'),
                                default => new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not connected</span> — configure credentials for the active environment'),
                            })
                            ->columnSpanFull(),
                        TextInput::make('fedex_production_account_number')
                            ->label('Production Account Number')
                            ->maxLength(50)
                            ->readOnly(fn (?CarrierAccount $record): bool => $record !== null
                                && filled($record->secret('child_key'))
                                && ($record->credential('child_env') ?? 'production') === 'production')
                            ->afterStateHydrated(fn ($component, ?CarrierAccount $record) => $component->state($record?->fedexAccountNumber('production')))
                            ->visible(fn (): bool => self::fields()->showsFedexEnvironment('production')),
                        TextInput::make('fedex_sandbox_account_number')
                            ->label('Sandbox Account Number')
                            ->helperText('Use the test account number assigned with your FedEx sandbox API project.')
                            ->maxLength(50)
                            ->readOnly(fn (?CarrierAccount $record): bool => $record !== null
                                && filled($record->secret('child_key'))
                                && $record->credential('child_env') === 'sandbox')
                            ->afterStateHydrated(fn ($component, ?CarrierAccount $record) => $component->state($record?->fedexAccountNumber('sandbox')))
                            ->visible(fn (): bool => self::fields()->showsFedexEnvironment('sandbox')),
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
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->visible(fn (): bool => self::fields()->showsFedexEnvironment('production')),
                        TextInput::make('fedex_adv_api_secret')
                            ->label('Production API Secret')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('api_secret')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->visible(fn (): bool => self::fields()->showsFedexEnvironment('production')),
                        TextInput::make('fedex_adv_sandbox_api_key')
                            ->label('Sandbox API Key')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('sandbox_api_key')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->visible(fn (): bool => self::fields()->showsFedexEnvironment('sandbox')),
                        TextInput::make('fedex_adv_sandbox_api_secret')
                            ->label('Sandbox API Secret')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('sandbox_api_secret')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->visible(fn (): bool => self::fields()->showsFedexEnvironment('sandbox')),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::FEDEX
                        && self::fields()->showsCarrierAppCredentials(Carrier::FEDEX))
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
                    ->description(fn (): string => self::fields()->offersCarrierOAuth(Carrier::UPS)
                        ? 'UPS account connection. Use the "Connect UPS" action above to authorize via OAuth.'
                        : 'UPS account connection. Enter a UPS developer app\'s client ID and secret under Advanced / API App Credentials.')
                    ->schema([
                        TextEntry::make('ups_oauth_status')
                            ->label('OAuth Status')
                            ->state(fn (?CarrierAccount $record, Get $get): HtmlString => static::renderAccountOauthStatus($record, Carrier::UPS, $get('ups_adv_client_id')))
                            ->visible(fn (): bool => self::fields()->offersCarrierOAuth(Carrier::UPS))
                            ->columnSpanFull(),
                        TextInput::make('ups_account_number')
                            ->label('Account Number')
                            ->maxLength(50)
                            ->afterStateHydrated(fn ($component, ?CarrierAccount $record) => $component->state($record?->credential('account_number'))),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::UPS)
                    ->collapsible(),

                Section::make('Advanced / API App Credentials')
                    ->description(fn (): string => self::appCredentialsDescription(Carrier::UPS))
                    ->schema([
                        TextInput::make('ups_adv_client_id')
                            ->label('Client ID')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('client_id')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->live(onBlur: true),
                        TextInput::make('ups_adv_client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->placeholder(fn (?CarrierAccount $record): string => filled($record?->secret('client_secret')) ? 'Configured (leave empty to keep)' : 'Not configured')
                            ->afterStateHydrated(fn ($component) => $component->state(null))
                            ->dehydrated(fn ($state): bool => filled($state)),
                    ])
                    ->visible(fn (Get $get): bool => Carrier::find($get('carrier_id'))?->name === Carrier::UPS
                        && self::fields()->showsCarrierAppCredentials(Carrier::UPS))
                    ->columns(2)
                    ->collapsed(fn (): bool => self::fields()->offersCarrierOAuth(Carrier::UPS))
                    ->collapsible(),
            ]);
    }

    /**
     * Where USPS's prepaid-duties terms are published.
     */
    public const string DDP_TERMS_URL = 'https://zonos.com/docs/legal/usps-terms-of-service';

    public static function renderDdpTermsStatus(?CarrierAccount $record): HtmlString
    {
        $link = '<a href="'.e(self::DDP_TERMS_URL).'" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline">terms of service</a>';

        if ($record?->hasAcceptedDdpTerms()) {
            $by = $record->ddpTermsAcceptedBy?->name;

            return new HtmlString('<span class="text-success-600 dark:text-success-400 font-medium">Accepted</span> '
                .e($record->ddp_terms_accepted_at->format('M j, Y'))
                .($by !== null ? ' by '.e($by) : '')
                .' — USPS DDP rates are offered on this account. The '.$link.'.');
        }

        return new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not accepted</span> — USPS DDP rates are not offered on this account until an Admin accepts the '.$link.' using the "Accept USPS DDP terms" action above.');
    }

    /**
     * On a new account, what creating it will do, by the same rule as the Create
     * button and the redirect after it.
     */
    private static function renderAccountOauthStatus(?CarrierAccount $record, string $carrierName, ?string $ownClientId): HtmlString
    {
        if (! $record?->exists) {
            $oauth = app(OAuthService::class);

            $message = match (true) {
                $oauth->providerForNewCarrierAccount($carrierName, $ownClientId) !== null => "After you create this account you will be sent to {$carrierName} to authorize it, and brought back here.",
                ! $oauth->isBrokerConfigured() => (string) $oauth->brokerlessGuidance("Enter your own {$carrierName} developer app credentials under Advanced / API App Credentials instead."),
                filled($ownClientId) => 'This account will sign in with the client ID and secret entered under Advanced / API App Credentials.',
                default => "The hosted OAuth connection issues production credentials only, so it cannot be used against the sandbox. Enter a {$carrierName} developer app's client ID and secret under Advanced / API App Credentials.",
            };

            return new HtmlString('<span class="text-gray-400 dark:text-gray-500">'.e($message).'</span>');
        }

        if (app(OAuthService::class)->isAccountConnected($record)) {
            $connectedAt = $record->credential('oauth_connected_at');
            $time = $connectedAt ? Carbon::parse($connectedAt)->diffForHumans() : null;

            return new HtmlString(
                '<span class="text-success-600 dark:text-success-400 font-medium">Connected</span>'
                    .($time ? " — {$time}" : '')
            );
        }

        if (filled($record->secret('client_id')) && filled($record->secret('client_secret'))) {
            return new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not connected</span> — signs in with this account\'s own client ID and secret instead');
        }

        return new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not connected</span>');
    }

    private static function renderUspsPricingTier(?CarrierAccount $record): HtmlString
    {
        if (! $record?->exists) {
            return new HtmlString('<span class="text-gray-400 dark:text-gray-500">Save and test the account to detect its pricing tier.</span>');
        }

        return match (app(UspsAdapter::class)->cachedPricingType($record)) {
            'CONTRACT' => new HtmlString('<span class="text-success-600 dark:text-success-400 font-medium">Contract pricing</span> — negotiated rates'),
            'RETAIL' => new HtmlString('<span class="text-warning-600 dark:text-warning-400 font-medium">Retail pricing</span> — standard rates (no EPS contract access)'),
            default => new HtmlString('<span class="text-gray-400 dark:text-gray-500">Not tested yet</span>'),
        };
    }
}
