<?php

namespace App\Http\Integrations\Shopify;

use App\Contracts\OAuthProvider;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

    public function getScopes(): array
    {
        return ['read_orders', 'write_fulfillments', 'read_products'];
    }

    /**
     * Get the shop domain (always per-tenant, from Settings or .env).
     */
    private function getShopDomain(): string
    {
        return app(SettingsService::class)->get('shopify.shop_domain', config('services.shopify.shop_domain')) ?? '';
    }

    /**
     * Get OAuth app credentials for the authorization code flow.
     *
     * In proxy mode (SaaS), credentials come from .env (shared across tenants).
     * In direct mode (on-prem), credentials come from SettingsService (per-tenant).
     *
     * @return array{client_id: string, client_secret: string}
     */
    private function getOAuthCredentials(): array
    {
        if (config('services.oauth.proxy_url')) {
            // Proxy mode: use shared credentials from .env, not per-tenant DB overrides
            return [
                'client_id' => config('services.shopify.client_id') ?? '',
                'client_secret' => config('services.shopify.client_secret') ?? '',
            ];
        }

        // Direct mode: use SettingsService (DB with .env fallback)
        $settings = app(SettingsService::class);

        return [
            'client_id' => $settings->get('shopify.client_id', config('services.shopify.client_id')) ?? '',
            'client_secret' => $settings->get('shopify.client_secret', config('services.shopify.client_secret')) ?? '',
        ];
    }

    public function getAuthorizationUrl(string $state, ?string $redirectUri = null): string
    {
        $shopDomain = $this->getShopDomain();
        $credentials = $this->getOAuthCredentials();

        if (empty($shopDomain) || empty($credentials['client_id'])) {
            throw new RuntimeException('Shopify shop domain and client ID must be configured before connecting.');
        }

        $redirectUri ??= route('oauth.callback', ['provider' => 'shopify']);

        $params = http_build_query([
            'client_id' => $credentials['client_id'],
            'scope' => implode(',', $this->getScopes()),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return "https://{$shopDomain}/admin/oauth/authorize?{$params}";
    }

    public function validateCallback(array $params): void
    {
        $hmac = $params['hmac'] ?? '';

        if (empty($hmac)) {
            throw new RuntimeException('Missing HMAC in Shopify callback.');
        }

        $credentials = $this->getOAuthCredentials();

        // Remove hmac from params, sort remaining alphabetically, compute HMAC
        $filtered = collect($params)
            ->except('hmac')
            ->sortKeys()
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode('&');

        $computed = hash_hmac('sha256', $filtered, $credentials['client_secret']);

        if (! hash_equals($computed, $hmac)) {
            throw new RuntimeException('Invalid Shopify HMAC signature.');
        }

        // Validate shop parameter format
        $shop = $params['shop'] ?? '';
        if (! empty($shop) && ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop)) {
            throw new RuntimeException('Invalid Shopify shop hostname.');
        }
    }

    public function exchangeCodeForToken(string $code, array $callbackParams): array
    {
        $shopDomain = $this->getShopDomain();
        $credentials = $this->getOAuthCredentials();

        $response = Http::asForm()->post(
            "https://{$shopDomain}/admin/oauth/access_token",
            [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'code' => $code,
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('Shopify token exchange failed: '.$response->body());
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new RuntimeException('Shopify token response missing access_token: '.$response->body());
        }

        return $data;
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
}
