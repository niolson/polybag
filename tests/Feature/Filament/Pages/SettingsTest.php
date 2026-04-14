<?php

use App\Exceptions\FedexRegistrationMaxRetriesException;
use App\Filament\Pages\Settings;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\Registration\SendPin;
use App\Http\Integrations\Fedex\Requests\Registration\ValidateAddress;
use App\Http\Integrations\Fedex\Requests\Registration\VerifyInvoice;
use App\Http\Integrations\Fedex\Requests\Registration\VerifyPin;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\FedexRegistrationService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

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

it('mounts sandbox_mode and suppress_printing from settings', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Livewire::test(Settings::class)
        ->assertSet('data.sandbox_mode', true)
        ->assertSet('data.suppress_printing', true);
});

it('saves sandbox_mode setting', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('sandbox_mode'))->toBeTrue();
});

it('saves suppress_printing setting when sandbox_mode is on', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
            'suppress_printing' => true,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('suppress_printing'))->toBeTrue();
});

it('forces suppress_printing to false when sandbox_mode is turned off', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => false,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('suppress_printing'))->toBeFalse();
});

it('clears API auth caches when sandbox_mode changes', function (): void {
    Cache::put('usps_authenticator', 'test-token', 3600);
    Cache::put('usps_payment_authorization_token', 'test-payment-token', 3600);
    Cache::put('fedex_authenticator', 'test-fedex-token', 3600);

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
        ])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeFalse()
        ->and(Cache::has('usps_payment_authorization_token'))->toBeFalse()
        ->and(Cache::has('fedex_authenticator'))->toBeFalse();
});

it('does not clear API auth caches when sandbox_mode does not change', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Cache::put('usps_authenticator', 'test-token', 3600);
    Cache::put('fedex_authenticator', 'test-fedex-token', 3600);

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
        ])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeTrue()
        ->and(Cache::has('fedex_authenticator'))->toBeTrue();
});

it('escapes oauth scopes in the settings page', function (): void {
    $payload = '<img src=x onerror=alert(\'pwnd\')>';

    Setting::create(['key' => 'shopify.oauth_access_token', 'value' => 'token', 'type' => 'string', 'encrypted' => true, 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.oauth_connected_at', 'value' => now()->toIso8601String(), 'type' => 'string', 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.oauth_scopes', 'value' => $payload, 'type' => 'string', 'group' => 'shopify']);
    app(SettingsService::class)->clearCache();

    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertSee(e($payload), false)
        ->assertDontSee($payload, false);
});

it('shows the fedex eula confidentiality footer', function (): void {
    $renderedEula = Blade::render("@include('filament.pages.settings.fedex-eula')");

    expect($renderedEula)
        ->toContain('FedEx Confidential')
        ->toContain('FedEx Form No. 2002382 v 4 June 2024 Rev');
});

it('saves ssh server host key and writes a known_hosts file', function (): void {
    $knownHostsPath = storage_path('app/private/ssh/import_known_hosts');
    @unlink($knownHostsPath);

    $hostKey = 'bastion.example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBastionKeyExample';

    Livewire::test(Settings::class)
        ->fillForm([
            'import_ssh_enabled' => true,
            'import_ssh_host_key' => $hostKey,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('import.ssh_host_key'))->toBe($hostKey);
    expect(file_exists($knownHostsPath))->toBeTrue();
    expect(trim((string) file_get_contents($knownHostsPath)))->toBe($hostKey);

    @unlink($knownHostsPath);
});

it('saves tenant-managed import and marketplace settings', function (): void {
    $shippingMethod = ShippingMethod::factory()->create();

    Livewire::test(Settings::class)
        ->fillForm([
            'import_source' => 'shopify',
            'shopify_import_enabled' => true,
            'shopify_export_enabled' => true,
            'shopify_channel_name' => 'Storefront',
            'shopify_shipping_method' => (string) $shippingMethod->id,
            'shopify_notify_customer' => true,
            'amazon_import_enabled' => true,
            'amazon_export_enabled' => true,
            'amazon_channel_name' => 'Marketplace',
            'amazon_shipping_method' => (string) $shippingMethod->id,
            'amazon_lookback_days' => 14,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();

    expect(app(SettingsService::class)->get('import_source'))->toBe('shopify')
        ->and(app(SettingsService::class)->get('shopify.import_enabled'))->toBeTrue()
        ->and(app(SettingsService::class)->get('shopify.export_enabled'))->toBeTrue()
        ->and(app(SettingsService::class)->get('shopify.channel_name'))->toBe('Storefront')
        ->and(app(SettingsService::class)->get('shopify.shipping_method'))->toBe((string) $shippingMethod->id)
        ->and(app(SettingsService::class)->get('shopify.notify_customer'))->toBeTrue()
        ->and(app(SettingsService::class)->get('amazon.import_enabled'))->toBeTrue()
        ->and(app(SettingsService::class)->get('amazon.export_enabled'))->toBeTrue()
        ->and(app(SettingsService::class)->get('amazon.channel_name'))->toBe('Marketplace')
        ->and(app(SettingsService::class)->get('amazon.shipping_method'))->toBe((string) $shippingMethod->id)
        ->and(app(SettingsService::class)->get('amazon.lookback_days'))->toBe(14);
});

it('saves database import and export sql queries', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'import_shipments_query' => 'select * from shipments where exported = 0',
            'import_shipment_items_query' => 'select * from shipment_items where shipment_id = :shipment_reference',
            'import_export_query' => 'update orders set tracking_number = :tracking_number where id = :shipment_reference',
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();

    expect(app(SettingsService::class)->get('import.shipments_query'))->toBe('select * from shipments where exported = 0')
        ->and(app(SettingsService::class)->get('import.shipment_items_query'))->toBe('select * from shipment_items where shipment_id = :shipment_reference')
        ->and(app(SettingsService::class)->get('import.export_query'))->toBe('update orders set tracking_number = :tracking_number where id = :shipment_reference');
});

it('test usps connection shows CONTRACT success notification', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['pricingOptions' => [['shippingOptions' => []]]], 200),
    ]);
    Cache::forget('usps_pricing_type');

    Livewire::test(Settings::class)
        ->call('testUspsConnection')
        ->assertNotified();

    expect(Cache::get('usps_pricing_type'))->toBe('CONTRACT');
});

it('test usps connection shows RETAIL notification when CONTRACT returns 403', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['error' => ['code' => '403', 'message' => 'Not authorized']], 403),
    ]);
    Cache::forget('usps_pricing_type');

    Livewire::test(Settings::class)
        ->call('testUspsConnection')
        ->assertNotified();

    expect(Cache::get('usps_pricing_type'))->toBe('RETAIL');
});

it('test usps connection shows danger notification when auth fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['error' => 'invalid_client'], 401),
    ]);
    Cache::forget('usps_pricing_type');

    Livewire::test(Settings::class)
        ->call('testUspsConnection')
        ->assertNotified();

    expect(Cache::get('usps_pricing_type'))->toBeNull();
});

it('displays pricing tier placeholder from cache on settings page', function (): void {
    Cache::put('usps_pricing_type', 'RETAIL', 3600);

    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertSee('RETAIL');
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

it('fedex account status shows not connected when no child key stored', function (): void {
    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertSee('Not connected');
});

it('fedex account status shows connected when child key is stored', function (): void {
    Setting::create(['key' => 'fedex.child_key', 'value' => 'some-child-key', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    app(SettingsService::class)->clearCache();

    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertSee('Connected');
});

it('fedex connector uses child key when present', function (): void {
    Setting::create(['key' => 'fedex.child_key', 'value' => 'child-key-123', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    Setting::create(['key' => 'fedex.child_secret', 'value' => 'child-secret-456', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    app(SettingsService::class)->clearCache();

    $connector = new FedexConnector;
    $config = (new ReflectionMethod($connector, 'defaultOauthConfig'))->invoke($connector);

    expect($config->getClientId())->toBe('child-key-123')
        ->and($config->getClientSecret())->toBe('child-secret-456');
});

it('fedex connector falls back to parent key when no child key', function (): void {
    // Global beforeEach seeds fedex.api_key = test_api_key and fedex.api_secret = test_api_secret
    app(SettingsService::class)->clearCache();

    $connector = new FedexConnector;
    $config = (new ReflectionMethod($connector, 'defaultOauthConfig'))->invoke($connector);

    expect($config->getClientId())->toBe('test_api_key')
        ->and($config->getClientSecret())->toBe('test_api_secret');
});

it('fedex registration service validates address and returns mfa options', function (): void {
    Storage::fake();

    Saloon::fake([
        ...fedexOauthMock(),
        ValidateAddress::class => MockResponse::make(fedexMfaResponse(), 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
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

it('fedex registration service saves child credentials after pin verification', function (): void {
    Saloon::fake([
        ...fedexOauthMock(),
        VerifyPin::class => MockResponse::make(fedexCredentialsResponse(), 200),
    ]);

    $credentials = app(FedexRegistrationService::class)->verifyPin('test-auth-token', '123456');

    app(FedexRegistrationService::class)->saveChildCredentials(
        $credentials['child_Key'],
        $credentials['child_secret'],
    );

    app(SettingsService::class)->clearCache();

    expect(Setting::where('key', 'fedex.child_key')->exists())->toBeTrue()
        ->and(Setting::where('key', 'fedex.child_secret')->exists())->toBeTrue();
});

it('fedex registration service saves child credentials after invoice verification', function (): void {
    Saloon::fake([
        ...fedexOauthMock(),
        VerifyInvoice::class => MockResponse::make(fedexCredentialsResponse(), 200),
    ]);

    $credentials = app(FedexRegistrationService::class)->verifyInvoice(
        accountAuthToken: 'test-auth-token',
        invoiceNumber: 234562278,
        invoiceDate: now()->subDays(30)->format('Y-m-d'),
        invoiceAmount: 234.00,
        invoiceCurrency: 'USD',
    );

    app(FedexRegistrationService::class)->saveChildCredentials(
        $credentials['child_Key'],
        $credentials['child_secret'],
    );

    app(SettingsService::class)->clearCache();

    expect(Setting::where('key', 'fedex.child_key')->exists())->toBeTrue();
});

it('fedex disconnect removes child credentials and clears authenticator cache', function (): void {
    Setting::create(['key' => 'fedex.child_key', 'value' => 'some-key', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    Setting::create(['key' => 'fedex.child_secret', 'value' => 'some-secret', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    Cache::put('fedex_authenticator', 'cached-token', 3600);
    app(SettingsService::class)->clearCache();

    app(FedexRegistrationService::class)->removeChildCredentials();

    app(SettingsService::class)->clearCache();

    expect(Setting::where('key', 'fedex.child_key')->exists())->toBeFalse()
        ->and(Setting::where('key', 'fedex.child_secret')->exists())->toBeFalse()
        ->and(Cache::has('fedex_authenticator'))->toBeFalse();
});

it('fedex registration service mfa bypass returns credentials immediately', function (): void {
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

it('fedex registration service activates child credentials and captures child authorization artifacts', function (): void {
    Storage::fake();
    Http::fake([
        'https://broker.example.test/fedex/token' => Http::response([
            'access_token' => 'child-access-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    config([
        'services.oauth.broker_url' => 'https://broker.example.test',
        'services.oauth.instance_id' => 'test-instance',
        'services.oauth.broker_secret' => 'test-secret',
    ]);

    app(FedexRegistrationService::class)->activateChildCredentials('child-key-123', 'child-secret-456');
    app(SettingsService::class)->clearCache();

    expect(app(SettingsService::class)->get('fedex.child_key'))->toBe('child-key-123')
        ->and(app(SettingsService::class)->get('fedex.child_secret'))->toBe('child-secret-456');

    Storage::assertExists('fedex-mfa/latest/child-authorization/request.json');
    Storage::assertExists('fedex-mfa/latest/child-authorization/response.json');
});

it('fedex registration service maps current fedex max retry codes to the fallback exception', function (): void {
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
        app(FedexRegistrationService::class)->verifyPin('test-auth-token', '123456');
        $this->fail('Expected FedEx max retry exception was not thrown.');
    } catch (FedexRegistrationMaxRetriesException $exception) {
        expect($exception->fedexCode)->toBe('PINVALIDATION.MAXRETRY.EXCEEDED')
            ->and($exception->lockedMethods)->toBe(['SMS', 'CALL', 'EMAIL']);
    }
});

it('settings page filters exhausted fedex verification methods', function (): void {
    $page = Livewire::test(Settings::class)->instance();

    $page->fedexSecureCodeOptions = ['SMS', 'CALL', 'EMAIL'];
    $page->fedexMaskedPhone = '***-***-1234';
    $page->fedexMaskedEmail = 'TE***@EX***.COM';
    $page->fedexInvoiceAvailable = true;
    $page->fedexLockedFactor2Methods = ['SMS', 'CALL', 'EMAIL'];

    expect($page->getFedexAvailableVerificationOptions())
        ->toBe(['INVOICE' => 'Invoice Validation'])
        ->and($page->hasAvailableFedexFactor2Methods())->toBeTrue();
});

it('settings page reports when all fedex verification methods are exhausted', function (): void {
    $page = Livewire::test(Settings::class)->instance();

    $page->fedexSecureCodeOptions = ['SMS', 'CALL', 'EMAIL'];
    $page->fedexInvoiceAvailable = true;
    $page->fedexLockedFactor2Methods = ['SMS', 'CALL', 'EMAIL', 'INVOICE'];

    expect($page->getFedexAvailableVerificationOptions())
        ->toBe([])
        ->and($page->hasAvailableFedexFactor2Methods())->toBeFalse();
});

it('fedex registration service routes through proxy when broker url is configured', function (): void {
    config([
        'services.oauth.broker_url' => 'https://polybag-connect.example.com',
        'services.oauth.instance_id' => 'test-instance',
        'services.oauth.broker_secret' => 'test-secret',
    ]);

    Saloon::fake([
        ValidateAddress::class => MockResponse::make(fedexMfaResponse(), 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
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
