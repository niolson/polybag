<?php

namespace App\Contracts;

use App\Services\SettingsService;

interface OAuthProvider
{
    /**
     * Unique provider key, e.g. 'shopify', 'amazon'.
     */
    public function getKey(): string;

    /**
     * Human-readable name for UI display.
     */
    public function getDisplayName(): string;

    /**
     * Which auth flows this provider supports.
     *
     * @return array<string> e.g. ['client_credentials', 'authorization_code']
     */
    public function getSupportedAuthModes(): array;

    /**
     * Settings key where the OAuth access token is stored.
     */
    public function getTokenSettingsKey(): string;

    /**
     * Settings key for the refresh token, or null if the provider doesn't use them.
     */
    public function getRefreshTokenSettingsKey(): ?string;

    /**
     * Revoke the token with the provider. Best-effort; noop if unsupported.
     */
    public function revokeToken(string $accessToken): void;

    /**
     * Extra parameters to pass to the broker's authorize endpoint.
     * e.g. Shopify needs ['shop' => 'mystore.myshopify.com']
     *
     * @return array<string, string>
     */
    public function getBrokerParams(): array;

    /**
     * Called after a successful OAuth connection. Providers can use this to
     * extract and persist account-specific data from the access token.
     */
    public function afterConnect(string $accessToken, SettingsService $settings): void;
}
