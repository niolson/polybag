<?php

use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\User;
use App\Services\OAuthService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The OAuth broker is a hosted-only service. A self-hoster can never be issued an
 * OAUTH_BROKER_SECRET, so nothing user-facing may tell them to go and set one.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    config([
        'services.oauth.broker_url' => null,
        'services.oauth.broker_secret' => null,
        'services.oauth.instance_id' => null,
    ]);
});

$configureBroker = fn () => config([
    'services.oauth.broker_url' => 'https://broker.example.com',
    'services.oauth.broker_secret' => 'broker-secret',
    'services.oauth.instance_id' => 'test-instance',
]);

$uspsAccount = fn (): CarrierAccount => CarrierAccount::factory()->usps()->create([
    'carrier_id' => Carrier::firstOrCreate(['name' => 'USPS'])->id,
]);

it('names the direct alternative instead of the broker env keys', function (): void {
    $guidance = app(OAuthService::class)->brokerlessGuidance(
        'Enter your own USPS developer app credentials under Advanced / API App Credentials instead.'
    );

    expect($guidance)
        ->toContain('USPS developer app credentials')
        ->toContain('Advanced / API App Credentials')
        ->toContain('docs/self-hosting.md');

    expect(str_contains((string) $guidance, 'OAUTH_BROKER_SECRET'))->toBeFalse();
    expect(str_contains((string) $guidance, 'OAUTH_INSTANCE_ID'))->toBeFalse();
});

it('drops the guidance entirely once a broker is configured', function () use ($configureBroker): void {
    $configureBroker();

    expect(app(OAuthService::class)->brokerlessGuidance('Anything at all.'))->toBeNull();
});

it('disables the carrier OAuth connect actions without a broker', function () use ($uspsAccount): void {
    Livewire::test(EditCarrierAccount::class, ['record' => $uspsAccount()->id])
        ->assertActionDisabled('usps_connect');
});

it('enables the carrier OAuth connect actions once a broker is configured', function () use ($configureBroker, $uspsAccount): void {
    $configureBroker();

    Livewire::test(EditCarrierAccount::class, ['record' => $uspsAccount()->id])
        ->assertActionEnabled('usps_connect');
});

it('disables the Shopify and Amazon connect actions without a broker', function (): void {
    $shopify = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'settings' => ['shop_domain' => 'example-store.myshopify.com'],
    ]);
    $amazon = DataSource::factory()->create(['source_type' => AmazonSource::class]);

    Livewire::test(EditDataSource::class, ['record' => $shopify->id])
        ->assertActionDisabled('shopify_connect');

    Livewire::test(EditDataSource::class, ['record' => $amazon->id])
        ->assertActionDisabled('amazon_connect');
});

it('does not tell a brokerless caller to set OAUTH_BROKER_SECRET when a broker flow is invoked', function (): void {
    expect(fn () => app(OAuthService::class)->initiateSsoAuthorization('google'))
        ->toThrow(RuntimeException::class, 'Guided sign-in is not available');
});

it('ships a self-hosting doc and keeps hosted-only hostnames out of .env.example', function (): void {
    expect(file_exists(base_path('docs/self-hosting.md')))->toBeTrue();

    $env = (string) file_get_contents(base_path('.env.example'));

    expect(str_contains($env, 'connect.polybag.app'))->toBeFalse();
    expect(str_contains($env, 'updates.polybag.app'))->toBeFalse();
    expect($env)->toContain('docs/self-hosting.md');
});
