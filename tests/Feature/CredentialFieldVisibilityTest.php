<?php

use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\CredentialFieldVisibility;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    app(SettingsService::class)->clearCache();
});

function configureCredentialBroker(bool $configured): void
{
    config([
        'services.oauth.broker_url' => $configured ? 'https://broker.example.com' : null,
        'services.oauth.broker_secret' => $configured ? 'broker-secret' : null,
        'services.oauth.instance_id' => $configured ? 'test-instance' : null,
    ]);
}

function carrierAccountFor(string $carrierName): CarrierAccount
{
    return CarrierAccount::factory()->create([
        'carrier_id' => Carrier::firstOrCreate(['name' => $carrierName])->id,
        'name' => "{$carrierName} Account",
    ]);
}

dataset('modes and broker', [
    'production, broker' => ['production', true],
    'demo, broker' => ['demo', true],
    'dev, broker' => ['local', true],
    'production, no broker' => ['production', false],
    'demo, no broker' => ['demo', false],
    'dev, no broker' => ['local', false],
]);

it('shows Shopify and Amazon app credentials only in Dev or without a broker', function (string $environment, bool $broker): void {
    configureCredentialBroker($broker);
    $this->app['env'] = $environment;
    $shown = $environment === 'local' || ! $broker;

    $shopify = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'settings' => ['shop_domain' => 'example-store.myshopify.com'],
    ]);
    $amazon = DataSource::factory()->create(['source_type' => AmazonSource::class]);

    $assert = $shown ? 'assertFormFieldVisible' : 'assertFormFieldHidden';

    Livewire::test(EditDataSource::class, ['record' => $shopify->id])
        ->{$assert}('settings.client_id')
        ->{$assert}('settings.client_secret');

    Livewire::test(EditDataSource::class, ['record' => $amazon->id])
        ->{$assert}('settings.refresh_token')
        ->{$assert}('settings.client_id')
        ->{$assert}('settings.client_secret');
})->with('modes and broker');

it('shows USPS and UPS app credentials where their OAuth cannot connect them', function (string $environment, bool $broker, string $carrierName, string $prefix): void {
    configureCredentialBroker($broker);
    $this->app['env'] = $environment;
    $account = carrierAccountFor($carrierName);

    // Production with a broker signs in through OAuth. Demo forces sandbox,
    // where the broker's production-only OAuth cannot be used.
    $appCredentialsShown = $environment !== 'production' || ! $broker;
    $oauthOffered = $environment !== 'demo';

    $component = Livewire::test(EditCarrierAccount::class, ['record' => $account->id]);

    $appCredentialsShown
        ? $component->assertFormFieldVisible("{$prefix}_adv_client_id")->assertFormFieldVisible("{$prefix}_adv_client_secret")
        : $component->assertFormFieldHidden("{$prefix}_adv_client_id")->assertFormFieldHidden("{$prefix}_adv_client_secret");

    $oauthOffered
        ? $component->assertActionVisible("{$prefix}_connect")
        : $component->assertActionHidden("{$prefix}_connect");
})->with('modes and broker')->with([
    'USPS' => [Carrier::USPS, 'usps'],
    'UPS' => [Carrier::UPS, 'ups'],
]);

it('shows FedEx fields for the environment this mode uses', function (string $environment, bool $broker): void {
    configureCredentialBroker($broker);
    $this->app['env'] = $environment;
    $account = carrierAccountFor(Carrier::FEDEX);

    $production = $environment !== 'demo';
    $sandbox = $environment !== 'production';
    $appCredentials = $environment === 'local' || ! $broker;

    $visible = fn (bool $shown): string => $shown ? 'assertFormFieldVisible' : 'assertFormFieldHidden';

    Livewire::test(EditCarrierAccount::class, ['record' => $account->id])
        ->{$visible($production)}('fedex_production_account_number')
        ->{$visible($sandbox)}('fedex_sandbox_account_number')
        ->{$visible($appCredentials && $production)}('fedex_adv_api_key')
        ->{$visible($appCredentials && $production)}('fedex_adv_api_secret')
        ->{$visible($appCredentials && $sandbox)}('fedex_adv_sandbox_api_key')
        ->{$visible($appCredentials && $sandbox)}('fedex_adv_sandbox_api_secret');
})->with('modes and broker');

it('keeps a carrier with no broker path on its client credentials in production', function (): void {
    configureCredentialBroker(true);
    $this->app['env'] = 'production';

    expect(CarrierRegistry::connectsThroughBroker('OnTrac'))->toBeFalse()
        ->and(app(CredentialFieldVisibility::class)->showsCarrierAppCredentials('OnTrac'))->toBeTrue()
        ->and(app(CredentialFieldVisibility::class)->showsCarrierAppCredentials(Carrier::USPS))->toBeFalse();
});

it('says which carriers the broker connects against each environment', function (): void {
    expect(CarrierRegistry::connectsThroughBroker(Carrier::USPS))->toBeTrue()
        ->and(CarrierRegistry::connectsThroughBroker(Carrier::USPS, sandbox: true))->toBeFalse()
        ->and(CarrierRegistry::connectsThroughBroker(Carrier::UPS, sandbox: true))->toBeFalse()
        ->and(CarrierRegistry::connectsThroughBroker(Carrier::FEDEX, sandbox: true))->toBeTrue();
});

it('keeps a hidden stored secret when the form is saved', function (): void {
    configureCredentialBroker(true);
    $this->app['env'] = 'production';

    $account = carrierAccountFor(Carrier::USPS);
    $account->mergeSecret('client_id', 'stored-client-id');
    $account->mergeSecret('client_secret', 'stored-client-secret');
    $account->save();

    Livewire::test(EditCarrierAccount::class, ['record' => $account->id])
        ->assertFormFieldHidden('usps_adv_client_id')
        ->call('save')
        ->assertHasNoFormErrors();

    $account->refresh();

    expect($account->secret('client_id'))->toBe('stored-client-id')
        ->and($account->secret('client_secret'))->toBe('stored-client-secret');
});

it('keeps a hidden FedEx account number when the form is saved in demo', function (): void {
    configureCredentialBroker(true);
    $this->app['env'] = 'demo';

    $account = carrierAccountFor(Carrier::FEDEX);
    $account->mergeCredential('production_account_number', '123456789');
    $account->mergeCredential('sandbox_account_number', '987654321');
    $account->save();

    Livewire::test(EditCarrierAccount::class, ['record' => $account->id])
        ->assertFormFieldHidden('fedex_production_account_number')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->refresh()->credential('production_account_number'))->toBe('123456789');
});

it('keeps a hidden Shopify app secret when the form is saved', function (): void {
    configureCredentialBroker(true);
    $this->app['env'] = 'production';

    $shopify = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'settings' => ['shop_domain' => 'example-store.myshopify.com'],
    ]);
    $shopify->mergeSecret('client_secret', 'stored-shopify-secret');
    $shopify->save();

    Livewire::test(EditDataSource::class, ['record' => $shopify->id])
        ->assertFormFieldHidden('settings.client_secret')
        ->call('save');

    expect($shopify->refresh()->secret('client_secret'))->toBe('stored-shopify-secret');
});
