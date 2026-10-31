<?php

use App\Console\Commands\DemoProvision;
use App\Enums\Role;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Channel;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\PhoneParserService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Database\Seeders\StoreChannelSeeder;

/**
 * `live-store-demo/04`: `demo:provision` sets up a demo tenant from the
 * environment, so the only thing left to do in the call is connect the
 * prospect's store.
 */
function demoSandboxCredentials(): void
{
    config()->set('demo.carriers', [
        'usps' => ['client_id' => 'usps-id', 'client_secret' => 'usps-secret', 'crid' => '11111111', 'mid' => '222222', 'eps_account' => null],
        'ups' => ['client_id' => 'ups-id', 'client_secret' => 'ups-secret', 'account_number' => 'A1B2C3'],
        'fedex' => ['api_key' => 'fedex-key', 'api_secret' => 'fedex-secret', 'account_number' => '700000000'],
    ]);
    config()->set('demo.amazon_shipping', [
        'client_id' => 'amzn1.application-oa2-client.demo',
        'client_secret' => 'amazon-secret',
        'refresh_token' => 'Atzr|demo-refresh',
        'marketplace_id' => 'ATVPDKIKX0DER',
    ]);
}

function demoAccountFor(string $carrierName): ?CarrierAccount
{
    return CarrierAccount::query()
        ->whereHas('carrier', fn ($q) => $q->where('name', $carrierName))
        ->first();
}

beforeEach(function (): void {
    $this->app['env'] = 'demo';
});

it('refuses to run outside Demo', function (string $environment): void {
    $this->app['env'] = $environment;
    demoSandboxCredentials();

    $this->artisan('demo:provision')->assertFailed();

    expect(CarrierAccount::count())->toBe(0)
        ->and(User::count())->toBe(0);
})->with(['production', 'local']);

it('creates each sandbox carrier account from the environment, assigned globally', function (): void {
    demoSandboxCredentials();

    $this->artisan('demo:provision')->assertSuccessful();

    $usps = demoAccountFor(Carrier::USPS);
    $ups = demoAccountFor(Carrier::UPS);
    $fedex = demoAccountFor(Carrier::FEDEX);

    expect($usps->credentials)->toBe(['crid' => '11111111', 'mid' => '222222'])
        ->and($usps->secret('client_id'))->toBe('usps-id')
        ->and($usps->secret('client_secret'))->toBe('usps-secret')
        ->and($ups->credential('account_number'))->toBe('A1B2C3')
        ->and($ups->secret('client_secret'))->toBe('ups-secret')
        ->and($fedex->credential('sandbox_account_number'))->toBe('700000000')
        ->and($fedex->secret('sandbox_api_key'))->toBe('fedex-key')
        ->and($fedex->secret('sandbox_api_secret'))->toBe('fedex-secret');

    // Demo forces sandbox, so each one counts as set up.
    foreach ([$usps, $ups, $fedex] as $account) {
        expect($account->active)->toBeTrue()
            ->and($account->hasUsableCredentials())->toBeTrue()
            ->and($account->scopes()->whereNull('location_id')->whereNull('client_id')->count())->toBe(1);
    }
});

it('skips a carrier whose values are missing, rather than creating it empty', function (): void {
    demoSandboxCredentials();
    config()->set('demo.carriers.ups.client_secret', null);

    $this->artisan('demo:provision')
        ->expectsOutputToContain('DEMO_UPS_CLIENT_SECRET')
        ->assertSuccessful();

    expect(demoAccountFor(Carrier::UPS))->toBeNull()
        ->and(demoAccountFor(Carrier::USPS))->not->toBeNull();
});

it('leaves a global default someone else already holds', function (): void {
    demoSandboxCredentials();
    $this->artisan('app:sync-reference-data');
    $existing = CarrierAccount::factory()->create([
        'carrier_id' => Carrier::where('name', Carrier::UPS)->value('id'),
        'name' => 'Our UPS',
    ]);
    $existing->scopes()->create(['location_id' => null, 'client_id' => null]);

    $this->artisan('demo:provision')
        ->expectsOutputToContain('another account is the global default')
        ->assertSuccessful();

    expect(CarrierAccountScope::query()->whereNull('location_id')->whereNull('client_id')
        ->where('carrier_id', $existing->carrier_id)->value('carrier_account_id'))->toBe($existing->id);
});

it('sets up Amazon Shipping on our sandbox app, selling postage and importing nothing', function (): void {
    demoSandboxCredentials();

    $this->artisan('demo:provision')->assertSuccessful();

    $connection = DataSource::query()->where('source_type', AmazonSource::class)->sole();

    expect(DemoProvision::isDemoConnection($connection))->toBeTrue()
        ->and($connection->active)->toBeTrue()
        ->and($connection->import_enabled)->toBeFalse()
        ->and($connection->importsOrders())->toBeFalse()
        ->and($connection->global_export)->toBeFalse()
        ->and($connection->settings['export_enabled'])->toBeFalse()
        ->and($connection->settings['auth_mode'])->toBe('manual')
        ->and($connection->settings['marketplace_ids'])->toBe(['ATVPDKIKX0DER'])
        ->and($connection->secret('refresh_token'))->toBe('Atzr|demo-refresh')
        ->and($connection->offersOffAmazonShipping())->toBeTrue()
        ->and(DataSource::resolveOffAmazonShipping(null, $connection->client_id)?->is($connection))->toBeTrue();

    $standardGround = ShippingMethod::where('name', DemoProvision::AMAZON_SHIPPING_METHOD)->sole();

    expect($standardGround->carrierServices()->where('service_code', 'std-us-swa-mfn')->exists())->toBeTrue();
});

it('seeds an origin every carrier accepts', function (): void {
    // The global setup makes a default Location; a fresh tenant has none.
    Location::query()->delete();

    $this->artisan('demo:provision')->assertSuccessful();

    $location = Location::where('is_default', true)->sole();

    expect($location->address1)->toBe('17301 133rd Ave NE')
        ->and([$location->city, $location->state_or_province, $location->postal_code])->toBe(['Woodinville', 'WA', '98072'])
        ->and($location->timezone)->toBe('America/Los_Angeles')
        ->and($location->phone_e164)->toBe('+14255550100')
        ->and(PhoneParserService::parse($location->phone, $location->country)->isValid())->toBeTrue();
});

it('seeds a channel for each store type, without the test channel', function (): void {
    $this->artisan('demo:provision')->assertSuccessful();

    expect(Channel::query()->orderBy('name')->pluck('name')->all())
        ->toBe([StoreChannelSeeder::AMAZON, 'Manual', StoreChannelSeeder::SHOPIFY]);
});

it('lists a ground service from every carrier on Standard Ground', function (): void {
    demoSandboxCredentials();

    $this->artisan('demo:provision')->assertSuccessful();

    expect(ShippingMethod::where('name', 'Standard Ground')->sole()->carrierServices()->pluck('service_code')->sort()->values()->all())
        ->toBe(['03', 'FEDEX_GROUND', 'GROUND_HOME_DELIVERY', 'USPS_GROUND_ADVANTAGE', 'std-us-swa-mfn']);
});

it('lets automation buy on the demo Amazon connection, filed under the Amazon channel', function (): void {
    demoSandboxCredentials();

    $this->artisan('demo:provision')->assertSuccessful();

    $connection = DataSource::query()->where('source_type', AmazonSource::class)->sole();

    expect($connection->sells_postage)->toBeTrue()
        ->and($connection->settings['channel_name'])->toBe(Channel::where('name', StoreChannelSeeder::AMAZON)->value('id'));
});

it('skips Amazon Shipping when our sandbox app is not configured', function (): void {
    demoSandboxCredentials();
    config()->set('demo.amazon_shipping.refresh_token', null);

    $this->artisan('demo:provision')
        ->expectsOutputToContain('DEMO_AMAZON_REFRESH_TOKEN')
        ->assertSuccessful();

    expect(DataSource::count())->toBe(0);
});

it('creates a presenter and a prospect, both Admins, and requires MFA', function (): void {
    $this->artisan('demo:provision', ['--client-name' => 'Acme Outfitters'])
        ->expectsOutputToContain('Shown once')
        ->assertSuccessful();

    expect(User::query()->orderBy('username')->pluck('role', 'username')->all())
        ->toBe(['presenter' => Role::Admin, 'prospect' => Role::Admin])
        ->and(app(SettingsService::class)->get('require_mfa'))->toBeTruthy()
        ->and(app(SettingsService::class)->get('setup_complete'))->toBeTruthy()
        ->and(Client::where('is_default', true)->value('name'))->toBe('Acme Outfitters');
});

it('can run again without duplicating anything or changing a password', function (): void {
    demoSandboxCredentials();
    $this->artisan('demo:provision')->assertSuccessful();
    $passwordHash = User::where('username', 'prospect')->value('password');
    $standardGroundServices = ShippingMethod::where('name', 'Standard Ground')->sole()->carrierServices()->count();
    Location::where('is_default', true)->update(['address1' => '1 Presenter Way']);

    $this->artisan('demo:provision')
        ->expectsOutputToContain('password unchanged')
        ->doesntExpectOutputToContain('Shown once')
        ->assertSuccessful();

    expect(CarrierAccount::count())->toBe(3)
        ->and(CarrierAccountScope::count())->toBe(4)
        ->and(DataSource::count())->toBe(1)
        ->and(User::count())->toBe(2)
        ->and(Channel::count())->toBe(3)
        ->and(Location::count())->toBe(1)
        ->and(Location::value('address1'))->toBe('1 Presenter Way')
        ->and(ShippingMethod::where('name', 'Standard Ground')->sole()->carrierServices()->count())->toBe($standardGroundServices)
        ->and(DataSource::sole()->settings['channel_name'])->toBe(Channel::where('name', StoreChannelSeeder::AMAZON)->value('id'))
        ->and(User::where('username', 'prospect')->value('password'))->toBe($passwordHash);
});
