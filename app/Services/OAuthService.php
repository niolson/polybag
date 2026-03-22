<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\OAuthStateEncoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class OAuthService
{
    public function __construct(
        private readonly OAuthProviderRegistry $registry,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Generate the authorization URL and store state in session.
     *
     * When OAUTH_PROXY_URL is configured, the state is encrypted with the
     * tenant's return URL so the proxy can route the callback back here.
     */
    public function initiateAuthorization(string $providerKey): string
    {
        $provider = $this->registry->get($providerKey);

        if (! in_array('authorization_code', $provider->getSupportedAuthModes())) {
            throw new RuntimeException("Provider '{$providerKey}' does not support authorization code flow.");
        }

        $nonce = Str::random(40);
        session()->put("oauth_state.{$providerKey}", $nonce);

        $proxyUrl = config('services.oauth.proxy_url');
        $proxySecret = config('services.oauth.proxy_secret');

        if ($proxyUrl && $proxySecret) {
            // Proxy mode: encrypt nonce + return URL into state
            $state = OAuthStateEncoder::encode($nonce, config('app.url'), $proxySecret);
            $redirectUri = rtrim($proxyUrl, '/')."/oauth/{$providerKey}/callback";
        } else {
            // Direct mode: plain nonce as state
            $state = $nonce;
            $redirectUri = null;
        }

        return $provider->getAuthorizationUrl($state, $redirectUri);
    }

    /**
     * Handle the OAuth callback: validate, exchange code, store tokens.
     */
    public function handleCallback(string $providerKey, array $params): void
    {
        $provider = $this->registry->get($providerKey);

        // Extract the nonce from state — in proxy mode, the state is encrypted
        $proxySecret = config('services.oauth.proxy_secret');
        $actualState = $params['state'] ?? '';

        if ($proxySecret) {
            $decoded = OAuthStateEncoder::decode($actualState, $proxySecret);
            if (! $decoded) {
                throw new RuntimeException('Failed to decrypt OAuth state. Check OAUTH_PROXY_SECRET.');
            }
            $actualNonce = $decoded['nonce'];
        } else {
            $actualNonce = $actualState;
        }

        // Validate nonce to prevent CSRF
        $expectedNonce = session()->pull("oauth_state.{$providerKey}");

        if (empty($expectedNonce) || ! hash_equals($expectedNonce, $actualNonce)) {
            throw new RuntimeException('OAuth state mismatch. Please try again.');
        }

        // Provider-specific validation (e.g. Shopify HMAC) — runs against original unmodified params
        $provider->validateCallback($params);

        // Exchange code for token
        $code = $params['code'] ?? '';
        if (empty($code)) {
            throw new RuntimeException('No authorization code received.');
        }

        $tokenData = $provider->exchangeCodeForToken($code, $params);

        $accessToken = $tokenData['access_token'] ?? null;
        if (empty($accessToken)) {
            throw new RuntimeException('No access token in provider response.');
        }

        // Store tokens in encrypted settings
        $group = $providerKey;
        $this->settings->set($provider->getTokenSettingsKey(), $accessToken, 'string', encrypted: true, group: $group);

        if ($provider->getRefreshTokenSettingsKey() && ! empty($tokenData['refresh_token'])) {
            $this->settings->set($provider->getRefreshTokenSettingsKey(), $tokenData['refresh_token'], 'string', encrypted: true, group: $group);
        }

        // Store granted scopes and connection timestamp
        $this->settings->set(
            "{$providerKey}.oauth_scopes",
            $tokenData['scope'] ?? implode(',', $provider->getScopes()),
            group: $group,
        );
        $this->settings->set("{$providerKey}.oauth_connected_at", now()->toIso8601String(), group: $group);

        // Set auth mode to authorization_code
        $this->settings->set("{$providerKey}.auth_mode", 'authorization_code', group: $group);

        // Clear any cached client_credentials token for this provider
        Cache::forget("{$providerKey}_access_token");
    }

    /**
     * Disconnect: clear OAuth tokens, revert to client_credentials.
     */
    public function disconnect(string $providerKey): void
    {
        $provider = $this->registry->get($providerKey);

        // Attempt revocation with provider (best-effort)
        $token = $this->settings->get($provider->getTokenSettingsKey());
        if ($token) {
            try {
                $provider->revokeToken($token);
            } catch (\Throwable) {
                // Best-effort; continue with local cleanup
            }
        }

        // Delete OAuth settings
        $keysToDelete = array_filter([
            $provider->getTokenSettingsKey(),
            $provider->getRefreshTokenSettingsKey(),
            "{$providerKey}.oauth_scopes",
            "{$providerKey}.oauth_connected_at",
            "{$providerKey}.auth_mode",
        ]);

        Setting::whereIn('key', $keysToDelete)->delete();

        $this->settings->clearCache();
        Cache::forget("{$providerKey}_access_token");
    }

    /**
     * Check if a provider is connected via OAuth.
     */
    public function isConnected(string $providerKey): bool
    {
        $provider = $this->registry->get($providerKey);

        return ! empty($this->settings->get($provider->getTokenSettingsKey()));
    }

    /**
     * Get the active auth mode for a provider.
     */
    public function getAuthMode(string $providerKey): string
    {
        return $this->settings->get("{$providerKey}.auth_mode", 'client_credentials');
    }
}
