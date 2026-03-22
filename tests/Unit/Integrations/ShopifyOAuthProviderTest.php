<?php

use App\Http\Integrations\Shopify\ShopifyOAuthProvider;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    app(SettingsService::class)->clearCache();

    // Default to direct mode (no proxy) so tests use DB credentials
    config(['services.oauth.proxy_url' => null, 'services.oauth.proxy_secret' => null]);

    Setting::create(['key' => 'shopify.shop_domain', 'value' => 'test-shop.myshopify.com', 'type' => 'string', 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.client_id', 'value' => 'test-client-id', 'type' => 'string', 'encrypted' => true, 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.client_secret', 'value' => 'test-client-secret', 'type' => 'string', 'encrypted' => true, 'group' => 'shopify']);

    app(SettingsService::class)->clearCache();
});

it('returns the correct provider key', function (): void {
    $provider = new ShopifyOAuthProvider;

    expect($provider->getKey())->toBe('shopify');
    expect($provider->getDisplayName())->toBe('Shopify');
});

it('supports both auth modes', function (): void {
    $provider = new ShopifyOAuthProvider;

    expect($provider->getSupportedAuthModes())->toBe(['client_credentials', 'authorization_code']);
});

it('returns expected scopes', function (): void {
    $provider = new ShopifyOAuthProvider;

    expect($provider->getScopes())->toBe(['read_orders', 'write_fulfillments', 'read_products']);
});

it('builds the correct authorization URL', function (): void {
    $provider = new ShopifyOAuthProvider;
    $state = 'test-state-abc123';

    $url = $provider->getAuthorizationUrl($state);

    expect($url)->toStartWith('https://test-shop.myshopify.com/admin/oauth/authorize?');
    expect($url)->toContain('client_id=test-client-id');
    expect($url)->toContain('scope=read_orders%2Cwrite_fulfillments%2Cread_products');
    expect($url)->toContain('state=test-state-abc123');
    expect($url)->toContain('redirect_uri=');
});

it('uses custom redirect URI when provided', function (): void {
    $provider = new ShopifyOAuthProvider;
    $proxyRedirect = 'https://connect.polybag.app/oauth/shopify/callback';

    $url = $provider->getAuthorizationUrl('test-state', $proxyRedirect);

    expect($url)->toContain(urlencode($proxyRedirect));
});

it('throws when building authorization URL without shop domain', function (): void {
    // Override settings to empty and clear config fallbacks
    Setting::where('key', 'shopify.shop_domain')->update(['value' => '']);
    Setting::where('key', 'shopify.client_id')->update(['value' => '', 'encrypted' => false]);
    app(SettingsService::class)->clearCache();
    config(['services.shopify.shop_domain' => null, 'services.shopify.client_id' => null]);

    $provider = new ShopifyOAuthProvider;

    $provider->getAuthorizationUrl('test-state');
})->throws(RuntimeException::class, 'Shopify shop domain and client ID must be configured');

it('validates callback with correct HMAC', function (): void {
    $provider = new ShopifyOAuthProvider;
    $secret = app(SettingsService::class)->get('shopify.client_secret');

    $params = [
        'code' => 'test-code',
        'shop' => 'test-shop.myshopify.com',
        'state' => 'test-state',
        'timestamp' => '1234567890',
    ];

    // Build the HMAC the same way the provider validates it
    $message = collect($params)
        ->sortKeys()
        ->map(fn ($value, $key) => "{$key}={$value}")
        ->implode('&');

    $params['hmac'] = hash_hmac('sha256', $message, $secret);

    // Should not throw
    $provider->validateCallback($params);
    expect(true)->toBeTrue();
});

it('throws on invalid HMAC', function (): void {
    $provider = new ShopifyOAuthProvider;

    $params = [
        'code' => 'test-code',
        'shop' => 'test-shop.myshopify.com',
        'state' => 'test-state',
        'timestamp' => '1234567890',
        'hmac' => 'invalid-hmac-value',
    ];

    $provider->validateCallback($params);
})->throws(RuntimeException::class, 'Invalid Shopify HMAC signature');

it('throws on missing HMAC', function (): void {
    $provider = new ShopifyOAuthProvider;

    $provider->validateCallback(['code' => 'test-code', 'shop' => 'test-shop.myshopify.com']);
})->throws(RuntimeException::class, 'Missing HMAC');

it('throws on invalid shop hostname format', function (): void {
    $provider = new ShopifyOAuthProvider;
    $secret = app(SettingsService::class)->get('shopify.client_secret');

    $params = [
        'code' => 'test-code',
        'shop' => 'evil-site.com',
        'state' => 'test-state',
    ];

    $message = collect($params)
        ->sortKeys()
        ->map(fn ($value, $key) => "{$key}={$value}")
        ->implode('&');

    $params['hmac'] = hash_hmac('sha256', $message, $secret);

    $provider->validateCallback($params);
})->throws(RuntimeException::class, 'Invalid Shopify shop hostname');

it('exchanges code for token successfully', function (): void {
    Http::fake([
        'test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_test_token_123',
            'scope' => 'read_orders,write_fulfillments,read_products',
        ]),
    ]);

    $provider = new ShopifyOAuthProvider;
    $result = $provider->exchangeCodeForToken('test-auth-code', []);

    expect($result)->toHaveKey('access_token', 'shpat_test_token_123');
    expect($result)->toHaveKey('scope', 'read_orders,write_fulfillments,read_products');
});

it('throws when token exchange fails', function (): void {
    Http::fake([
        'test-shop.myshopify.com/admin/oauth/access_token' => Http::response('Unauthorized', 401),
    ]);

    $provider = new ShopifyOAuthProvider;
    $provider->exchangeCodeForToken('bad-code', []);
})->throws(RuntimeException::class, 'Shopify token exchange failed');

it('has correct settings keys', function (): void {
    $provider = new ShopifyOAuthProvider;

    expect($provider->getTokenSettingsKey())->toBe('shopify.oauth_access_token');
    expect($provider->getRefreshTokenSettingsKey())->toBeNull();
});
