<?php

namespace App\Contracts;

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
     * Scopes requested during authorization.
     *
     * @return array<string>
     */
    public function getScopes(): array;

    /**
     * Build the authorization URL for the auth code flow.
     *
     * @param  string  $state  The state parameter (nonce or encrypted payload)
     * @param  string|null  $redirectUri  Override callback URL (used by proxy mode)
     */
    public function getAuthorizationUrl(string $state, ?string $redirectUri = null): string;

    /**
     * Exchange the authorization code for tokens.
     *
     * @param  array<string, string>  $callbackParams  Full callback query params
     * @return array<string, mixed> Must contain 'access_token', may contain 'refresh_token', 'scope', 'expires_in'
     */
    public function exchangeCodeForToken(string $code, array $callbackParams): array;

    /**
     * Validate provider-specific callback security (e.g. Shopify HMAC).
     *
     * @param  array<string, string>  $params  Callback query params
     *
     * @throws \RuntimeException on validation failure
     */
    public function validateCallback(array $params): void;

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
}
