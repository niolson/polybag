<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OAuthService
{
    public function __construct(
        private readonly OAuthProviderRegistry $registry,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Generate the broker authorization URL and store nonce in session.
     */
    public function initiateAuthorization(string $providerKey): string
    {
        $provider = $this->registry->get($providerKey);

        if (! in_array('authorization_code', $provider->getSupportedAuthModes())) {
            throw new RuntimeException("Provider '{$providerKey}' does not support authorization code flow.");
        }

        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        if (! $brokerUrl || ! $brokerSecret || ! $instanceId) {
            throw new RuntimeException('OAuth broker is not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID.');
        }

        $nonce = Str::random(40);
        session()->put("oauth_state.{$providerKey}", $nonce);

        $returnUrl = config('app.url');
        $signature = hash_hmac('sha256', "{$providerKey}:{$instanceId}:{$returnUrl}:{$nonce}", $brokerSecret);

        $params = array_filter([
            'return_url' => $returnUrl,
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
            ...$provider->getBrokerParams(),
        ]);

        return rtrim($brokerUrl, '/')."/oauth/{$providerKey}/authorize?".http_build_query($params);
    }

    /**
     * Handle the broker's redirect back: claim tokens via server-to-server call.
     */
    public function handleReceive(string $providerKey, string $transferCode): void
    {
        $provider = $this->registry->get($providerKey);

        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        $signature = hash_hmac('sha256', $transferCode, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/').'/oauth/claim', [
            'transfer_code' => $transferCode,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to claim tokens from broker: '.$response->body());
        }

        $data = $response->json();

        // Validate nonce to prevent CSRF
        $expectedNonce = session()->pull("oauth_state.{$providerKey}");

        if (empty($expectedNonce) || ! hash_equals($expectedNonce, $data['nonce'] ?? '')) {
            throw new RuntimeException('OAuth state mismatch. Please try again.');
        }

        $accessToken = $data['access_token'] ?? null;
        if (empty($accessToken)) {
            throw new RuntimeException('No access token received from broker.');
        }

        // Store tokens in encrypted settings
        $group = $providerKey;
        $this->settings->set($provider->getTokenSettingsKey(), $accessToken, 'string', encrypted: true, group: $group);

        if ($provider->getRefreshTokenSettingsKey() && ! empty($data['refresh_token'])) {
            $this->settings->set($provider->getRefreshTokenSettingsKey(), $data['refresh_token'], 'string', encrypted: true, group: $group);
        }

        // Store token expiry if provided
        if (! empty($data['expires_in'])) {
            $this->settings->set(
                "{$providerKey}.oauth_token_expires_at",
                now()->addSeconds((int) $data['expires_in'])->toIso8601String(),
                group: $group,
            );
        }

        // Store granted scopes and connection timestamp
        $scopes = $data['extra']['scope'] ?? $data['scope'] ?? '';
        $this->settings->set("{$providerKey}.oauth_scopes", $scopes, group: $group);
        $this->settings->set("{$providerKey}.oauth_connected_at", now()->toIso8601String(), group: $group);

        // Set auth mode to authorization_code
        $this->settings->set("{$providerKey}.auth_mode", 'authorization_code', group: $group);

        // Clear any cached client_credentials token for this provider
        Cache::forget("{$providerKey}_access_token");
    }

    /**
     * Refresh an expired token via the broker.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    public function refreshToken(string $providerKey): array
    {
        $provider = $this->registry->get($providerKey);
        $refreshTokenKey = $provider->getRefreshTokenSettingsKey();

        if (! $refreshTokenKey) {
            throw new RuntimeException("Provider '{$providerKey}' does not support token refresh.");
        }

        $refreshToken = $this->settings->get($refreshTokenKey);

        if (empty($refreshToken)) {
            throw new RuntimeException("No refresh token stored for '{$providerKey}'.");
        }

        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        $signature = hash_hmac('sha256', $refreshToken, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/')."/oauth/{$providerKey}/refresh", [
            'refresh_token' => $refreshToken,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Token refresh failed: '.$response->body());
        }

        $data = $response->json();
        $group = $providerKey;

        // Update stored tokens
        if (! empty($data['access_token'])) {
            $this->settings->set($provider->getTokenSettingsKey(), $data['access_token'], 'string', encrypted: true, group: $group);
        }

        if (! empty($data['refresh_token'])) {
            $this->settings->set($refreshTokenKey, $data['refresh_token'], 'string', encrypted: true, group: $group);
        }

        if (! empty($data['expires_in'])) {
            $this->settings->set(
                "{$providerKey}.oauth_token_expires_at",
                now()->addSeconds((int) $data['expires_in'])->toIso8601String(),
                group: $group,
            );
        }

        $this->settings->clearCache();

        return $data;
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
            "{$providerKey}.oauth_token_expires_at",
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
