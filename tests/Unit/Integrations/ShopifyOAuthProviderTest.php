<?php

use App\Http\Integrations\Shopify\ShopifyOAuthProvider;
use App\Models\Setting;
use App\Services\SettingsService;

beforeEach(function (): void {
    app(SettingsService::class)->clearCache();

    Setting::updateOrCreate(['key' => 'shopify.shop_domain'], ['value' => 'test-shop.myshopify.com', 'type' => 'string', 'group' => 'shopify']);

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

it('has correct settings keys', function (): void {
    $provider = new ShopifyOAuthProvider;

    expect($provider->getTokenSettingsKey())->toBe('shopify.oauth_access_token');
    expect($provider->getRefreshTokenSettingsKey())->toBeNull();
});

it('returns shop domain in broker params', function (): void {
    $provider = new ShopifyOAuthProvider;

    $params = $provider->getBrokerParams();

    expect($params)->toHaveKey('shop', 'test-shop.myshopify.com');
});

it('returns empty broker params when shop domain is not set', function (): void {
    Setting::where('key', 'shopify.shop_domain')->update(['value' => '']);
    app(SettingsService::class)->clearCache();

    $provider = new ShopifyOAuthProvider;

    expect($provider->getBrokerParams())->toBe([]);
});
