<?php

use App\Http\Integrations\Shopify\ShopifyConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::forget('shopify_access_token_'.md5('test-shop.myshopify.com'));
});

function shopifyAuthConfig(array $overrides = []): array
{
    return array_merge([
        'shop_domain' => 'test-shop.myshopify.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ], $overrides);
}

it('rejects a shop domain that is not a *.myshopify.com host', function (string $domain): void {
    expect(fn (): ShopifyConnector => ShopifyConnector::fromSettings(shopifyAuthConfig(['shop_domain' => $domain])))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'arbitrary attacker host' => 'internal-metadata-service.attacker-controlled.invalid',
    'bare domain' => 'evil.com',
    'suffix smuggling' => 'shop.myshopify.com.evil.com',
    'path smuggling' => 'shop.myshopify.com/../evil.com',
]);

it('accepts a valid *.myshopify.com shop domain', function (): void {
    $connector = ShopifyConnector::fromSettings(shopifyAuthConfig([
        'oauth_access_token' => 'shpat_oauth_token',
    ]));

    expect($connector->resolveBaseUrl())
        ->toBe('https://test-shop.myshopify.com/admin/api/2026-10/graphql.json');
});

it('uses the per-source OAuth token when one is provided', function (): void {
    $connector = ShopifyConnector::fromSettings(shopifyAuthConfig([
        'oauth_access_token' => 'shpat_oauth_token',
    ]));

    // The connector should use the injected OAuth token in its headers.
    $headers = $connector->headers()->all();
    expect($headers['X-Shopify-Access-Token'])->toBe('shpat_oauth_token');
});

it('uses client credentials when no token is provided', function (): void {
    Http::fake([
        'test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_cc_token',
            'expires_in' => 86399,
        ]),
    ]);

    $connector = ShopifyConnector::fromSettings(shopifyAuthConfig());

    $headers = $connector->headers()->all();
    expect($headers['X-Shopify-Access-Token'])->toBe('shpat_cc_token');
});

it('falls back to client credentials when the OAuth token is empty', function (): void {
    Http::fake([
        'test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_fallback_token',
            'expires_in' => 86399,
        ]),
    ]);

    $connector = ShopifyConnector::fromSettings(shopifyAuthConfig([
        'oauth_access_token' => null,
    ]));

    $headers = $connector->headers()->all();
    expect($headers['X-Shopify-Access-Token'])->toBe('shpat_fallback_token');
});
