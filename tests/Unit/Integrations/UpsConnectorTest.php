<?php

use App\Exceptions\Carriers\CarrierUnavailableException;
use App\Http\Integrations\Ups\UpsConnector;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function upsAccountWithClientCredentials(): CarrierAccount
{
    return CarrierAccount::factory()->create([
        'carrier_id' => Carrier::factory()->ups(),
        'name' => 'UPS Account',
        'secret_credentials' => ['client_id' => 'abc', 'client_secret' => 'shh'],
    ]);
}

function enableSandboxMode(): void
{
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();
}

it('namespaces the per-account token cache key by environment', function (): void {
    $account = upsAccountWithClientCredentials();

    $productionKey = UpsConnector::authenticatorCacheKeyForAccount($account->id);

    enableSandboxMode();

    expect($productionKey)->toBe("ups_authenticator:{$account->id}")
        ->and(UpsConnector::authenticatorCacheKeyForAccount($account->id))
        ->toBe("ups_authenticator_sandbox:{$account->id}");
});

it('does not reuse a production token after switching to sandbox mode', function (): void {
    $account = upsAccountWithClientCredentials();

    Cache::put(UpsConnector::authenticatorCacheKeyForAccount($account->id), [
        'access_token' => 'production-token',
        'refresh_token' => null,
        'expires_at' => now()->addHours(4)->getTimestamp(),
    ], 3600);

    enableSandboxMode();

    Saloon::fake([
        'wwwcie.ups.com/security/v1/oauth/token' => MockResponse::make([
            'access_token' => 'sandbox-token',
            'token_type' => 'Bearer',
            'expires_in' => 14399,
        ], 200),
    ]);

    $connector = UpsConnector::getAuthenticatedConnector($account);

    $authenticator = $connector->getAuthenticator();
    expect($authenticator)->toBeInstanceOf(AccessTokenAuthenticator::class);

    /** @var AccessTokenAuthenticator $authenticator */

    // The sandbox connector must mint its own token, not present the production one.
    expect($authenticator->getAccessToken())->toBe('sandbox-token')
        ->and(Cache::get('ups_authenticator:'.$account->id)['access_token'])->toBe('production-token');
});

it('refuses an OAuth-connected account in sandbox mode', function (): void {
    $account = CarrierAccount::factory()->create([
        'carrier_id' => Carrier::factory()->ups(),
        'name' => 'UPS Account',
        'secret_credentials' => ['auth_mode' => 'authorization_code', 'oauth_token' => 'production-token'],
    ]);

    enableSandboxMode();

    UpsConnector::getAuthenticatedConnector($account);
})->throws(CarrierUnavailableException::class, 'UPS is not available in sandbox mode when connected via OAuth');
