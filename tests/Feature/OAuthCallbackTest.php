<?php

use App\Enums\Role;
use App\Http\Integrations\Shopify\ShopifyOAuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\OAuthProviderRegistry;
use App\Services\OAuthService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    app(SettingsService::class)->clearCache();

    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => str_repeat('ab', 32),
        'services.oauth.instance_id' => 'test-instance',
        'app.url' => 'https://test.polybag.app',
    ]);

    Setting::updateOrCreate(['key' => 'shopify.shop_domain'], ['value' => 'test-shop.myshopify.com', 'type' => 'string', 'group' => 'shopify']);

    app(SettingsService::class)->clearCache();

    // Ensure Shopify provider is registered
    $registry = app(OAuthProviderRegistry::class);
    if (! $registry->has('shopify')) {
        $registry->register(new ShopifyOAuthProvider);
    }
});

it('stores tokens on valid receive with transfer code', function (): void {
    $user = User::factory()->admin()->create();
    $nonce = 'valid-nonce-token';

    // Fake the broker's claim endpoint
    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'shopify',
            'access_token' => 'shpat_live_token',
            'refresh_token' => null,
            'expires_in' => null,
            'nonce' => $nonce,
            'extra' => ['scope' => 'read_orders,write_fulfillments,read_products'],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => $nonce])
        ->get('/oauth/shopify/receive?transfer_code=abc123');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'success');

    // Verify tokens were stored
    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('shopify.oauth_access_token'))->toBe('shpat_live_token');
    expect(app(SettingsService::class)->get('shopify.auth_mode'))->toBe('authorization_code');
    expect(app(SettingsService::class)->get('shopify.oauth_connected_at'))->not->toBeNull();
});

it('forbids non-admin users from receiving oauth callbacks', function (): void {
    $user = User::factory()->create(['role' => Role::User]);

    Http::fake();

    $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => 'nonce'])
        ->get('/oauth/shopify/receive?transfer_code=abc123')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('rejects receive with mismatched nonce', function (): void {
    $user = User::factory()->admin()->create();

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'shopify',
            'access_token' => 'shpat_token',
            'nonce' => 'broker-nonce',
            'extra' => [],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => 'different-nonce'])
        ->get('/oauth/shopify/receive?transfer_code=abc123');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');

    // Verify no tokens were stored
    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('shopify.oauth_access_token'))->toBeNull();
});

it('handles error redirect from broker', function (): void {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->get('/oauth/shopify/receive?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
    $response->assertSessionHas('oauth_notification.title', 'Connection failed: User denied access');
});

it('handles missing transfer code', function (): void {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->get('/oauth/shopify/receive');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
});

it('handles broker claim failure', function (): void {
    $user = User::factory()->admin()->create();

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response('Transfer code not found', 404),
    ]);

    $response = $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => 'nonce'])
        ->get('/oauth/shopify/receive?transfer_code=expired-code');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
});

it('initiates authorization via broker', function (): void {
    $oauthService = app(OAuthService::class);
    $url = $oauthService->initiateAuthorization('shopify');

    // Should redirect to the broker, not directly to Shopify
    expect($url)->toStartWith('https://connect.polybag.app/oauth/shopify/authorize?');
    expect($url)->toContain('instance_id=test-instance');
    expect($url)->toContain(urlencode('https://test.polybag.app'));
    expect($url)->toContain('signature=');
    expect($url)->toContain('shop=test-shop.myshopify.com');

    // Nonce should be stored in session
    expect(session('oauth_state.shopify'))->not->toBeNull();
    expect(strlen(session('oauth_state.shopify')))->toBe(40);
});

it('throws when broker is not configured', function (): void {
    config([
        'services.oauth.broker_url' => null,
        'services.oauth.broker_secret' => null,
        'services.oauth.instance_id' => null,
    ]);

    $oauthService = app(OAuthService::class);
    $oauthService->initiateAuthorization('shopify');
})->throws(RuntimeException::class, 'OAuth broker is not configured');

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
