<?php

use App\Http\Integrations\Shopify\ShopifyOAuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\OAuthProviderRegistry;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Support\OAuthStateEncoder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    app(SettingsService::class)->clearCache();

    // Default to direct mode (no proxy) — individual tests override as needed
    config(['services.oauth.proxy_url' => null, 'services.oauth.proxy_secret' => null]);

    Setting::create(['key' => 'shopify.shop_domain', 'value' => 'test-shop.myshopify.com', 'type' => 'string', 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.client_id', 'value' => 'test-client-id', 'type' => 'string', 'encrypted' => true, 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.client_secret', 'value' => 'test-secret', 'type' => 'string', 'encrypted' => true, 'group' => 'shopify']);

    app(SettingsService::class)->clearCache();

    // Ensure Shopify provider is registered
    $registry = app(OAuthProviderRegistry::class);
    if (! $registry->has('shopify')) {
        $registry->register(new ShopifyOAuthProvider);
    }
});

it('stores tokens on valid callback', function (): void {
    $user = User::factory()->admin()->create();
    $secret = app(SettingsService::class)->get('shopify.client_secret');

    Http::fake([
        'test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_live_token',
            'scope' => 'read_orders,write_fulfillments,read_products',
        ]),
    ]);

    // Build valid callback params with HMAC
    $state = 'valid-state-token';
    $params = [
        'code' => 'auth-code-123',
        'shop' => 'test-shop.myshopify.com',
        'state' => $state,
        'timestamp' => (string) time(),
    ];

    $message = collect($params)
        ->sortKeys()
        ->map(fn ($value, $key) => "{$key}={$value}")
        ->implode('&');

    $params['hmac'] = hash_hmac('sha256', $message, $secret);

    // Set the expected state in session
    $response = $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => $state])
        ->get('/oauth/shopify/callback?'.http_build_query($params));

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'success');

    // Verify tokens were stored
    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('shopify.oauth_access_token'))->toBe('shpat_live_token');
    expect(app(SettingsService::class)->get('shopify.auth_mode'))->toBe('authorization_code');
    expect(app(SettingsService::class)->get('shopify.oauth_scopes'))->toBe('read_orders,write_fulfillments,read_products');
    expect(app(SettingsService::class)->get('shopify.oauth_connected_at'))->not->toBeNull();
});

it('rejects callback with mismatched state', function (): void {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => 'expected-state'])
        ->get('/oauth/shopify/callback?code=test&state=wrong-state&hmac=fake');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');

    // Verify no tokens were stored
    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('shopify.oauth_access_token'))->toBeNull();
});

it('rejects callback with invalid HMAC', function (): void {
    $user = User::factory()->admin()->create();
    $state = 'valid-state';

    $params = [
        'code' => 'auth-code',
        'shop' => 'test-shop.myshopify.com',
        'state' => $state,
        'timestamp' => (string) time(),
        'hmac' => 'definitely-not-valid',
    ];

    $response = $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => $state])
        ->get('/oauth/shopify/callback?'.http_build_query($params));

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
});

it('rejects callback for unregistered provider', function (): void {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->withSession(['oauth_state.unknown' => 'state'])
        ->get('/oauth/unknown/callback?code=test&state=state');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
});

it('initiates authorization with proxy URL when configured', function (): void {
    config([
        'services.oauth.proxy_url' => 'https://connect.polybag.app',
        'services.oauth.proxy_secret' => str_repeat('ab', 32),
        'app.url' => 'https://acme.polybag.app',
    ]);

    $oauthService = app(OAuthService::class);
    $url = $oauthService->initiateAuthorization('shopify');

    // Should redirect to Shopify with the proxy callback URL
    expect($url)->toStartWith('https://test-shop.myshopify.com/admin/oauth/authorize?');
    expect($url)->toContain(urlencode('https://connect.polybag.app/oauth/shopify/callback'));

    // The state should be an encrypted payload, not a plain nonce
    parse_str(parse_url($url, PHP_URL_QUERY), $queryParams);
    $state = $queryParams['state'];

    // It should be decodable with the proxy secret
    $decoded = OAuthStateEncoder::decode($state, str_repeat('ab', 32));
    expect($decoded)->not->toBeNull();
    expect($decoded['return_url'])->toBe('https://acme.polybag.app');
    expect($decoded['nonce'])->toBe(session('oauth_state.shopify'));
});

it('initiates authorization without proxy when not configured', function (): void {
    config([
        'services.oauth.proxy_url' => null,
        'services.oauth.proxy_secret' => null,
    ]);

    $oauthService = app(OAuthService::class);
    $url = $oauthService->initiateAuthorization('shopify');

    // Should use the local callback URL
    expect($url)->toContain(urlencode(route('oauth.callback', ['provider' => 'shopify'])));

    // The state should be a plain nonce (40 chars)
    parse_str(parse_url($url, PHP_URL_QUERY), $queryParams);
    expect(strlen($queryParams['state']))->toBe(40);
});

it('disconnects and clears OAuth tokens', function (): void {
    // Set up as if connected
    $settings = app(SettingsService::class);
    $settings->set('shopify.oauth_access_token', 'token-to-remove', 'string', encrypted: true, group: 'shopify');
    $settings->set('shopify.auth_mode', 'authorization_code', group: 'shopify');
    $settings->set('shopify.oauth_scopes', 'read_orders', group: 'shopify');
    $settings->set('shopify.oauth_connected_at', now()->toIso8601String(), group: 'shopify');
    $settings->clearCache();

    $oauthService = app(OAuthService::class);
    expect($oauthService->isConnected('shopify'))->toBeTrue();
    expect($oauthService->getAuthMode('shopify'))->toBe('authorization_code');

    // Disconnect
    $oauthService->disconnect('shopify');

    $settings->clearCache();
    expect($oauthService->isConnected('shopify'))->toBeFalse();
    expect($oauthService->getAuthMode('shopify'))->toBe('client_credentials');
    expect($settings->get('shopify.oauth_access_token'))->toBeNull();
    expect($settings->get('shopify.oauth_scopes'))->toBeNull();
    expect($settings->get('shopify.oauth_connected_at'))->toBeNull();
});
