<?php

namespace App\Http\Integrations\Shopify;

use App\Contracts\OAuthProvider;
use App\Services\SettingsService;

class ShopifyOAuthProvider implements OAuthProvider
{
    public function getKey(): string
    {
        return 'shopify';
    }

    public function getDisplayName(): string
    {
        return 'Shopify';
    }

    public function getSupportedAuthModes(): array
    {
        return ['client_credentials', 'authorization_code'];
    }

    public function getTokenSettingsKey(): string
    {
        return 'shopify.oauth_access_token';
    }

    public function getRefreshTokenSettingsKey(): ?string
    {
        // Shopify offline access tokens are non-expiring by default
        return null;
    }

    public function revokeToken(string $accessToken): void
    {
        // Shopify has no token revocation endpoint; local cleanup only
    }

    public function getBrokerParams(): array
    {
        $shopDomain = app(SettingsService::class)->get('shopify.shop_domain');

        return array_filter(['shop' => $shopDomain]);
    }
}
