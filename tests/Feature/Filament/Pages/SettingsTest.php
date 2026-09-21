<?php

use App\Enums\LabelReferenceSource;
use App\Exceptions\FedexRegistrationMaxRetriesException;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\Registration\SendPin;
use App\Http\Integrations\Fedex\Requests\Registration\ValidateAddress;
use App\Http\Integrations\Fedex\Requests\Registration\VerifyInvoice;
use App\Http\Integrations\Fedex\Requests\Registration\VerifyPin;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Setting;
use App\Models\User;
use App\Services\FedexRegistrationService;
use App\Services\LabelReferenceResolver;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows sandbox mode indicator in topbar when sandbox mode is enabled', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $this->get('/')->assertSeeText('(sandbox mode)');
});

it('does not show sandbox mode indicator when sandbox mode is disabled', function (): void {
    app(SettingsService::class)->clearCache();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('(sandbox mode)</span>');
});

it('shows the fedex eula confidentiality footer', function (): void {
    $renderedEula = Blade::render("@include('filament.pages.settings.fedex-eula')");

    expect($renderedEula)
        ->toContain('FedEx Confidential')
        ->toContain('FedEx Form No. 2002382 v 4 June 2024 Rev');
});

// ─── FedEx Account Registration ───────────────────────────────────────────────

function fedexOauthMock(): array
{
    return ['*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600])];
}

function fedexMfaResponse(): array
{
    return [
        'output' => [
            'mfaOptions' => [[
                'accountAuthToken' => 'test-auth-token',
                'mfaRequired' => true,
                'email' => 'TE***@EX***.COM',
                'phoneNumber' => '***-***-1234',
                'options' => [
                    'invoice' => 'INVOICE',
                    'secureCode' => ['SMS', 'CALL', 'EMAIL'],
                ],
            ]],
        ],
    ];
}

function fedexCredentialsResponse(): array
{
    return [
        'output' => [
            'credentials' => [
                'child_Key' => 'test-child-key',
                'child_secret' => 'test-child-secret',
            ],
        ],
    ];
}

it('fedex account status is not shown on settings page (moved to carrier accounts)', function (): void {
    // FedEx connection status moved to the Carrier Accounts edit page.
    Setting::create(['key' => 'fedex.child_key', 'value' => 'some-child-key', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    app(SettingsService::class)->clearCache();

    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertDontSee('Account Status');
});

it('fedex connector keeps parent oauth config when child key is present', function (): void {
    $account = createFedexAccount(
        ['child_key' => 'child-key-123', 'child_secret' => 'child-secret-456'],
    );

    $connector = FedexConnector::forAccount($account);
    $config = (new ReflectionMethod($connector, 'defaultOauthConfig'))->invoke($connector);

    expect($config->getClientId())->toBe('test_api_key')
        ->and($config->getClientSecret())->toBe('test_api_secret');
});

it('fedex connector falls back to parent key when no child key', function (): void {
    $account = createFedexAccount();

    $connector = FedexConnector::forAccount($account);
    $config = (new ReflectionMethod($connector, 'defaultOauthConfig'))->invoke($connector);

    expect($config->getClientId())->toBe('test_api_key')
        ->and($config->getClientSecret())->toBe('test_api_secret');
});

it('fedex registration service validates address and returns mfa options', function (): void {
    Storage::fake();
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        ValidateAddress::class => MockResponse::make(fedexMfaResponse(), 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
        account: $account,
        accountNumber: '700257037',
        customerName: 'Test Company',
        residential: false,
        street1: '15 W 18TH ST FL 7',
        street2: '',
        city: 'NEW YORK',
        stateOrProvinceCode: 'NY',
        postalCode: '10011',
        countryCode: 'US',
    );

    expect($result['mfaRequired'])->toBeTrue()
        ->and($result['accountAuthToken'])->toBe('test-auth-token')
        ->and($result['email'])->toBe('TE***@EX***.COM');

    Saloon::assertSent(ValidateAddress::class);
    Storage::assertExists('fedex-mfa/latest/address-validation/request.json');
    Storage::assertExists('fedex-mfa/latest/address-validation/response.json');
});

it('explains fedex account and address classification mismatches', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        ValidateAddress::class => MockResponse::make([
            'errors' => [[
                'code' => 'USER.ACCOUNT.NOTFOUND',
                'message' => 'Account Not Found in Database',
            ]],
        ], 400),
    ]);

    expect(fn () => app(FedexRegistrationService::class)->validateAddress(
        account: $account,
        accountNumber: '700257037',
        customerName: 'Test Company',
        residential: true,
        street1: '15 W 18TH ST FL 7',
        street2: '',
        city: 'NEW YORK',
        stateOrProvinceCode: 'NY',
        postalCode: '10011',
        countryCode: 'US',
    ))->toThrow(RuntimeException::class, 'FedEx could not match the account number, customer name, address, and residential classification. Check that these values match the FedEx account record. For a home-based business, leave Residential off unless FedEx classifies the account address as residential.');
});

it('explains fedex pin delivery failures and shared retry limits', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        SendPin::class => MockResponse::make([
            'errors' => [[
                'code' => 'PIN.ISSUE.FAILED',
                'message' => 'PIN Generation failed. Please try again later.',
            ]],
        ], 400),
    ]);

    expect(fn () => app(FedexRegistrationService::class)->sendPin($account, 'test-auth-token', 'EMAIL'))
        ->toThrow(RuntimeException::class, 'FedEx could not send a PIN using that method. Try another available method, but avoid repeated requests because FedEx limits PIN attempts across email, SMS, and phone.');
});

it('fedex registration service saves child credentials after pin verification', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        VerifyPin::class => MockResponse::make(fedexCredentialsResponse(), 200),
    ]);

    $credentials = app(FedexRegistrationService::class)->verifyPin($account, 'test-auth-token', '123456');

    app(FedexRegistrationService::class)->saveChildCredentialsToAccount(
        $credentials['child_Key'],
        $credentials['child_secret'],
        $account,
    );

    $account->refresh();
    expect($account->secret('child_key'))->toBe('test-child-key')
        ->and($account->secret('child_secret'))->toBe('test-child-secret')
        ->and($account->credential('child_env'))->toBe('production');
});

it('fedex registration saves the account number for the OAuth environment without replacing sandbox configuration', function (): void {
    $account = createFedexAccount(
        credentials: [
            'sandbox_account_number' => '740561073',
        ],
    );

    app(SettingsService::class)->set('sandbox_mode', false);

    app(FedexRegistrationService::class)->saveChildCredentialsToAccount(
        'production-child-key',
        'production-child-secret',
        $account,
        '210726418',
    );

    $account->refresh();

    expect($account->credential('production_account_number'))->toBe('210726418')
        ->and($account->credential('sandbox_account_number'))->toBe('740561073')
        ->and($account->credential('child_env'))->toBe('production');
});

it('fedex registration service saves child credentials to the account after invoice verification', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        VerifyInvoice::class => MockResponse::make(fedexCredentialsResponse(), 200),
    ]);

    $credentials = app(FedexRegistrationService::class)->verifyInvoice(
        account: $account,
        accountAuthToken: 'test-auth-token',
        invoiceNumber: 234562278,
        invoiceDate: now()->subDays(30)->format('Y-m-d'),
        invoiceAmount: 234.00,
        invoiceCurrency: 'USD',
    );

    app(FedexRegistrationService::class)->saveChildCredentialsToAccount(
        $credentials['child_Key'],
        $credentials['child_secret'],
        $account,
    );

    $account->refresh();
    expect($account->secret('child_key'))->toBe('test-child-key');
});

it('fedex registration service mfa bypass returns credentials immediately', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        ValidateAddress::class => MockResponse::make([
            'output' => [
                'credentials' => [
                    'child_Key' => 'bypass-child-key',
                    'child_secret' => 'bypass-child-secret',
                ],
            ],
        ], 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
        account: $account,
        accountNumber: '700257037',
        customerName: 'Test Company',
        residential: false,
        street1: '15 W 18TH ST FL 7',
        street2: '',
        city: 'NEW YORK',
        stateOrProvinceCode: 'NY',
        postalCode: '10011',
        countryCode: 'US',
    );

    expect($result['mfaRequired'])->toBeFalse()
        ->and($result['credentials']['child_Key'])->toBe('bypass-child-key');

    Saloon::assertNotSent(SendPin::class);
    Saloon::assertNotSent(VerifyPin::class);
});

it('fedex registration service maps current fedex max retry codes to the fallback exception', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        VerifyPin::class => MockResponse::make([
            'errors' => [[
                'code' => 'PINVALIDATION.MAXRETRY.EXCEEDED',
                'message' => 'max retry exceeded for PIN validation',
            ]],
        ], 400),
    ]);

    try {
        app(FedexRegistrationService::class)->verifyPin($account, 'test-auth-token', '123456');
        $this->fail('Expected FedEx max retry exception was not thrown.');
    } catch (FedexRegistrationMaxRetriesException $exception) {
        expect($exception->fedexCode)->toBe('PINVALIDATION.MAXRETRY.EXCEEDED')
            ->and($exception->lockedMethods)->toBe(['SMS', 'CALL', 'EMAIL'])
            ->and($exception->getMessage())->toBe('FedEx has temporarily blocked more PIN attempts. The retry limit is shared across email, SMS, and phone. Wait before starting over, or contact FedEx Customer Service for technical support.');
    }
});

it('carrier account edit page handles a fedex pin resend lockout', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        SendPin::class => MockResponse::make([
            'errors' => [[
                'code' => 'PINGENERATION.MAXRETRY.EXCEEDED',
                'message' => 'Max retry exceeded for PIN Generation.',
            ]],
        ], 400),
    ]);

    Livewire::test(EditCarrierAccount::class, ['record' => $account->id])
        ->set('fedexAccountAuthToken', 'test-auth-token')
        ->set('fedexFactor2Method', 'SMS')
        ->call('resendFedexPin')
        ->assertSet('fedexSupportFallbackActive', true)
        ->assertSet('fedexLockedFactor2Methods', ['SMS', 'CALL', 'EMAIL']);
});

it('carrier account edit page filters exhausted fedex verification methods', function (): void {
    $fedex = Carrier::factory()->fedex()->create();
    $account = CarrierAccount::factory()->fedex()->create(['carrier_id' => $fedex->id]);

    $page = Livewire::test(EditCarrierAccount::class, ['record' => $account->id])->instance();

    $page->fedexSecureCodeOptions = ['SMS', 'CALL', 'EMAIL'];
    $page->fedexMaskedPhone = '***-***-1234';
    $page->fedexMaskedEmail = 'TE***@EX***.COM';
    $page->fedexInvoiceAvailable = true;
    $page->fedexLockedFactor2Methods = ['SMS', 'CALL', 'EMAIL'];

    expect($page->getFedexAvailableVerificationOptions())
        ->toBe(['INVOICE' => 'Invoice Validation'])
        ->and($page->hasAvailableFedexFactor2Methods())->toBeTrue();
});

it('carrier account edit page reports when all fedex verification methods are exhausted', function (): void {
    $fedex = Carrier::factory()->fedex()->create();
    $account = CarrierAccount::factory()->fedex()->create(['carrier_id' => $fedex->id]);

    $page = Livewire::test(EditCarrierAccount::class, ['record' => $account->id])->instance();

    $page->fedexSecureCodeOptions = ['SMS', 'CALL', 'EMAIL'];
    $page->fedexInvoiceAvailable = true;
    $page->fedexLockedFactor2Methods = ['SMS', 'CALL', 'EMAIL', 'INVOICE'];

    expect($page->getFedexAvailableVerificationOptions())
        ->toBe([])
        ->and($page->hasAvailableFedexFactor2Methods())->toBeFalse();
});

it('carrier account edit page explains fedex residential classification', function (): void {
    $account = createFedexAccount();

    Livewire::test(EditCarrierAccount::class, ['record' => $account->id])
        ->mountAction('fedex_register')
        ->assertSchemaComponentExists(
            'fedex_reg_residential',
            checkComponentUsing: fn ($component): bool => $component->getLabel() === 'FedEx classifies this account address as residential',
        );
});

it('carrier account edit page saves separate FedEx account numbers by environment', function (): void {
    $account = createFedexAccount(
        secrets: ['child_key' => 'production-child-key', 'child_secret' => 'production-child-secret'],
        credentials: [
            'account_number' => '210726418',
            'child_env' => 'production',
        ],
    );

    Livewire::test(EditCarrierAccount::class, ['record' => $account->id])
        ->assertFormSet([
            'fedex_production_account_number' => '210726418',
            'fedex_sandbox_account_number' => null,
        ])
        ->fillForm(['fedex_sandbox_account_number' => '740561073'])
        ->call('save')
        ->assertHasNoFormErrors();

    $account->refresh();

    expect($account->credential('production_account_number'))->toBe('210726418')
        ->and($account->credential('sandbox_account_number'))->toBe('740561073')
        ->and($account->credential('account_number'))->toBe('210726418');
});

it('fedex registration service routes through proxy when broker url is configured', function (): void {
    $account = createFedexAccount();

    config([
        'services.oauth.broker_url' => 'https://polybag-connect.example.com',
        'services.oauth.instance_id' => 'test-instance',
        'services.oauth.broker_secret' => 'test-secret',
    ]);

    Saloon::fake([
        ValidateAddress::class => MockResponse::make(fedexMfaResponse(), 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
        account: $account,
        accountNumber: '700257037',
        customerName: 'Test Company',
        residential: false,
        street1: '15 W 18TH ST FL 7',
        street2: '',
        city: 'NEW YORK',
        stateOrProvinceCode: 'NY',
        postalCode: '10011',
        countryCode: 'US',
    );

    expect($result['mfaRequired'])->toBeTrue();

    // No OAuth token request — proxy connector handles auth on the broker side
    Saloon::assertNotSent('*oauth*');
    Saloon::assertSent(ValidateAddress::class);
});

// ─── Single-client mode: client fields in Settings ────────────────────────────

it('loads default client fields into settings form in single-client mode', function (): void {
    $client = Client::factory()->create([
        'is_default' => true,
        'company_name' => 'ACME Corporation',
        'custom_message' => 'Thank you!',
        'return_address1' => '123 Main St',
        'return_city' => 'Springfield',
        'return_state_or_province' => 'IL',
        'return_postal_code' => '62701',
        'return_country' => 'US',
    ]);

    Livewire::test(Settings::class)
        ->assertSet('data.client.company_name', 'ACME Corporation')
        ->assertSet('data.client.custom_message', 'Thank you!')
        ->assertSet('data.client.return_address1', '123 Main St');
});

it('saves default client fields from settings form in single-client mode', function (): void {
    $client = Client::factory()->create(['is_default' => true]);

    Livewire::test(Settings::class)
        ->fillForm([
            'client.company_name' => 'Updated Corp',
            'client.custom_message' => 'Thanks for shopping!',
            'client.return_address1' => '456 Elm St',
            'client.return_city' => 'Shelbyville',
            'client.return_state_or_province' => 'IL',
            'client.return_postal_code' => '62565',
            'client.return_country' => 'US',
        ])
        ->call('save')
        ->assertNotified();

    expect($client->fresh())
        ->company_name->toBe('Updated Corp')
        ->custom_message->toBe('Thanks for shopping!')
        ->return_address1->toBe('456 Elm St')
        ->return_city->toBe('Shelbyville');
});

it('saves the instance-wide label reference source and applies it to clients with no override', function (): void {
    Livewire::test(Settings::class)
        ->fillForm(['label_reference_source' => LabelReferenceSource::PackageId->value])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect(app(SettingsService::class)->get('label_reference_source'))->toBe(LabelReferenceSource::PackageId->value)
        ->and(app(LabelReferenceResolver::class)->instanceDefault())->toBe(LabelReferenceSource::PackageId);
});

it('defaults the label reference source to the shipment reference before it is ever saved', function (): void {
    Livewire::test(Settings::class)
        ->assertFormSet(['label_reference_source' => LabelReferenceSource::ShipmentReference]);
});

it('saves the Entra IdP-MFA trust settings from the form', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'trust_idp_mfa' => true,
            'trusted_azure_tids' => ['a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'],
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $settings = app(SettingsService::class);

    expect($settings->get('trust_idp_mfa'))->toBeTrue()
        ->and($settings->get('trusted_azure_tids'))->toBe(['a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d']);
});

it('canonicalizes trusted tenant ids to lowercase and de-duplicates on save', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'trust_idp_mfa' => true,
            'trusted_azure_tids' => [
                'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D',
                'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            ],
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect(app(SettingsService::class)->get('trusted_azure_tids'))
        ->toBe(['a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d']);
});

it('rejects a non-UUID trusted tenant id', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'trust_idp_mfa' => true,
            'trusted_azure_tids' => ['not-a-guid'],
        ])
        ->call('save')
        ->assertNotNotified();

    // Validation blocked the save, so the bad value never reached the store.
    expect(app(SettingsService::class)->get('trusted_azure_tids', []))->toBe([]);
});

it('preserves the trusted-tid allowlist when the trust toggle is turned off', function (): void {
    app(SettingsService::class)->set('trust_idp_mfa', true, type: 'boolean');
    app(SettingsService::class)->set('trusted_azure_tids', ['a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'], type: 'json');
    app(SettingsService::class)->clearCache();

    Livewire::test(Settings::class)
        ->fillForm(['trust_idp_mfa' => false])
        ->call('save')
        ->assertNotified();

    $settings = app(SettingsService::class);

    expect($settings->get('trust_idp_mfa'))->toBeFalse()
        ->and($settings->get('trusted_azure_tids'))->toBe(['a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d']);
});

it('does not load client fields into settings form in multi-client mode', function (): void {
    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    Client::factory()->create(['is_default' => true, 'company_name' => 'Should Not Load']);

    Livewire::test(Settings::class)
        ->assertSet('data.client.company_name', null);
});

it('does not overwrite client fields when saving in multi-client mode', function (): void {
    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $client = Client::factory()->create([
        'is_default' => true,
        'company_name' => 'Original Name',
    ]);

    Livewire::test(Settings::class)
        ->call('save')
        ->assertNotified();

    expect($client->fresh()->company_name)->toBe('Original Name');
});

// ─── Single-location mode: location fields in Settings ────────────────────────

it('loads default location fields into settings form in single-location mode', function (): void {
    $location = Location::factory()->create([
        'is_default' => true,
        'name' => 'Main Warehouse',
        'address1' => '123 Main St',
        'city' => 'Springfield',
        'state_or_province' => 'IL',
        'postal_code' => '62701',
        'country' => 'US',
        'timezone' => 'America/Chicago',
    ]);

    Livewire::test(Settings::class)
        ->assertSet('data.location.name', 'Main Warehouse')
        ->assertSet('data.location.address1', '123 Main St')
        ->assertSet('data.location.timezone', 'America/Chicago');
});

it('saves default location fields from settings form in single-location mode', function (): void {
    $location = Location::factory()->create([
        'is_default' => true,
        'name' => 'Old Name',
        'country' => 'US',
        'phone' => null,
    ]);

    Livewire::test(Settings::class)
        ->fillForm([
            'location.name' => 'New Warehouse',
            'location.country' => 'US',
            'location.first_name' => 'Shipping',
            'location.last_name' => 'Center',
            'location.address1' => '456 Elm St',
            'location.city' => 'Shelbyville',
            'location.state_or_province' => 'IL',
            'location.postal_code' => '62565',
            'location.timezone' => 'America/Chicago',
            'location.phone' => null,
        ])
        ->call('save')
        ->assertNotified();

    expect($location->fresh())
        ->name->toBe('New Warehouse')
        ->address1->toBe('456 Elm St')
        ->city->toBe('Shelbyville');
});

it('syncs carrier locations when saving location in single-location mode', function (): void {
    $location = Location::factory()->create(['is_default' => true, 'country' => 'US', 'phone' => null]);
    $carrier = Carrier::factory()->create(['name' => 'USPS', 'active' => true]);

    Livewire::test(Settings::class)
        ->fillForm([
            'location.name' => $location->name,
            'location.country' => 'US',
            'location.first_name' => $location->first_name,
            'location.last_name' => $location->last_name,
            'location.address1' => $location->address1,
            'location.city' => $location->city,
            'location.state_or_province' => $location->state_or_province,
            'location.postal_code' => $location->postal_code,
            'location.timezone' => $location->timezone,
            'location.phone' => null,
            'location.carrierLocations' => [
                ['carrier_id' => $carrier->id, 'pickup_days' => [1, 2, 3, 4, 5]],
            ],
        ])
        ->call('save')
        ->assertNotified();

    expect($location->fresh()->carrierLocations)
        ->toHaveCount(1)
        ->first()->carrier_id->toBe($carrier->id)
        ->first()->pickup_days->toBe([1, 2, 3, 4, 5]);
});

it('does not overwrite location fields when saving in multi-location mode', function (): void {
    Setting::create(['key' => 'multi_location_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $location = Location::factory()->create([
        'is_default' => true,
        'name' => 'Original Warehouse',
    ]);

    Livewire::test(Settings::class)
        ->call('save')
        ->assertNotified();

    expect($location->fresh()->name)->toBe('Original Warehouse');
});

// ─── MFA required while an active Amazon SP-API source exists ─────────────────

it('blocks disabling MFA while an active Amazon data source exists', function (): void {
    Setting::create(['key' => 'require_mfa', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => true,
    ]);

    Livewire::test(Settings::class)
        ->fillForm(['require_mfa' => false])
        ->call('save')
        ->assertHasErrors(['data.require_mfa']);

    expect(app(SettingsService::class)->get('require_mfa'))->toBeTrue();
});

it('allows disabling MFA when the only Amazon data source is inactive', function (): void {
    Setting::create(['key' => 'require_mfa', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => false,
    ]);

    Livewire::test(Settings::class)
        ->fillForm(['require_mfa' => false])
        ->call('save')
        ->assertNotified();

    expect(app(SettingsService::class)->get('require_mfa'))->toBeFalse();
});

it('refuses to save a carrier with no pickup days rather than storing an empty set', function (): void {
    // An empty set says nothing a carrier's absence from this list does not
    // already say — both fall back to the Mon-Fri default — and storing one used
    // to date labels for Sundays. Rejected rather than accepted and normalized,
    // so the form never shows nothing ticked beside behaviour that is Mon-Fri.
    $location = Location::factory()->create(['is_default' => true, 'country' => 'US', 'phone' => null]);
    $carrier = Carrier::factory()->create(['name' => 'USPS', 'active' => true]);

    Livewire::test(Settings::class)
        ->fillForm([
            'location.name' => $location->name,
            'location.country' => 'US',
            'location.first_name' => $location->first_name,
            'location.last_name' => $location->last_name,
            'location.address1' => $location->address1,
            'location.city' => $location->city,
            'location.state_or_province' => $location->state_or_province,
            'location.postal_code' => $location->postal_code,
            'location.timezone' => $location->timezone,
            'location.phone' => null,
            'location.carrierLocations' => [
                ['carrier_id' => $carrier->id, 'pickup_days' => []],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();

    expect($location->fresh()->carrierLocations)->toHaveCount(0);
});
