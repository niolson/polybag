<?php

use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    app(SettingsService::class)->clearCache();
    Cache::forget('shopify_access_token');

    Setting::create(['key' => 'shopify.shop_domain', 'value' => 'test-shop.myshopify.com', 'type' => 'string', 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.client_id', 'value' => 'test-client-id', 'type' => 'string', 'encrypted' => true, 'group' => 'shopify']);
    Setting::create(['key' => 'shopify.client_secret', 'value' => 'test-client-secret', 'type' => 'string', 'encrypted' => true, 'group' => 'shopify']);

    app(SettingsService::class)->clearCache();
});

it('uses OAuth token when auth mode is authorization_code', function (): void {
    $settings = app(SettingsService::class);
    $settings->set('shopify.auth_mode', 'authorization_code', group: 'shopify');
    $settings->set('shopify.oauth_access_token', 'shpat_oauth_token', 'string', encrypted: true, group: 'shopify');
    $settings->clearCache();

    $connector = ShopifyConnector::fromConfig();

    // The connector should use the OAuth token in its headers
    $headers = $connector->headers()->all();
    expect($headers['X-Shopify-Access-Token'])->toBe('shpat_oauth_token');
});

it('uses client credentials when auth mode is not set', function (): void {
    Http::fake([
        'test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_cc_token',
            'expires_in' => 86399,
        ]),
    ]);

    $connector = ShopifyConnector::fromConfig();

    $headers = $connector->headers()->all();
    expect($headers['X-Shopify-Access-Token'])->toBe('shpat_cc_token');
});

it('falls back to client credentials when OAuth token is missing', function (): void {
    $settings = app(SettingsService::class);
    $settings->set('shopify.auth_mode', 'authorization_code', group: 'shopify');
    // No oauth_access_token set
    $settings->clearCache();

    Http::fake([
        'test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_fallback_token',
            'expires_in' => 86399,
        ]),
    ]);

    $connector = ShopifyConnector::fromConfig();

    $headers = $connector->headers()->all();
    expect($headers['X-Shopify-Access-Token'])->toBe('shpat_fallback_token');
});
