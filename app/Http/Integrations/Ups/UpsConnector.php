<?php

namespace App\Http\Integrations\Ups;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\Concerns\RetriesTransientErrors;
use App\Services\OAuthService;
use App\Services\SettingsService;
use Carbon\Carbon;
use DateInterval;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\ClientCredentialsBasicAuthGrant;
use Saloon\Traits\Plugins\HasTimeout;

class UpsConnector extends Connector
{
    use ClientCredentialsBasicAuthGrant;
    use HasCachedAuthentication;
    use HasTimeout;
    use RetriesTransientErrors;

    protected int $connectTimeout = 5;

    public function getRequestTimeout(): float
    {
        return (float) app(SettingsService::class)->get('carrier_api_timeout', 15);
    }

    /**
     * Number of retry attempts for failed requests.
     */
    public ?int $tries = 3;

    /**
     * Interval in milliseconds between retry attempts.
     */
    public ?int $retryInterval = 500;

    /**
     * Use exponential backoff for retries.
     */
    public ?bool $useExponentialBackoff = true;

    public function resolveBaseUrl(): string
    {
        if (app(SettingsService::class)->get('sandbox_mode', false)) {
            return config('services.ups.sandbox_url', 'https://wwwcie.ups.com');
        }

        return config('services.ups.base_url', 'https://onlinetools.ups.com');
    }

    protected function defaultOauthConfig(): OAuthConfig
    {
        $settings = app(SettingsService::class);

        return OAuthConfig::make()
            ->setClientId((string) $settings->get('ups.client_id', ''))
            ->setClientSecret((string) $settings->get('ups.client_secret', ''))
            ->setTokenEndpoint('/security/v1/oauth/token');
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        return 'ups_authenticator';
    }

    /**
     * Get an authenticated connector, using OAuth token if connected via auth code flow,
     * otherwise falling back to client credentials.
     */
    public static function getAuthenticatedConnector(): static
    {
        $settings = app(SettingsService::class);

        if ($settings->get('ups.auth_mode') === 'authorization_code') {
            return static::fromOAuthToken($settings);
        }

        // Client credentials flow (parent trait behavior)
        return static::fromClientCredentials();
    }

    /**
     * Client credentials flow — delegates to the HasCachedAuthentication trait.
     */
    private static function fromClientCredentials(): static
    {
        /** @phpstan-ignore new.static */
        $connector = new static;
        $cacheKey = static::getAuthenticatorCacheKey();

        $authenticator = Cache::get($cacheKey);

        if (! $authenticator) {
            $authenticator = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($connector, $cacheKey) {
                $cached = Cache::get($cacheKey);
                if ($cached) {
                    return $cached;
                }

                $authenticator = $connector->getAccessToken();

                Cache::put(
                    $cacheKey,
                    $authenticator,
                    $authenticator->getExpiresAt()->sub(DateInterval::createFromDateString('10 minutes'))
                );

                return $authenticator;
            });
        }

        $connector->authenticate($authenticator);

        return $connector;
    }

    /**
     * OAuth authorization code flow — uses stored token with automatic refresh.
     */
    private static function fromOAuthToken(SettingsService $settings): static
    {
        /** @phpstan-ignore new.static */
        $connector = new static;
        $cacheKey = 'ups_oauth_token';

        // Try cache first
        $cachedToken = Cache::get($cacheKey);

        if ($cachedToken) {
            $connector->authenticate(new TokenAuthenticator($cachedToken));

            return $connector;
        }

        // Cache miss — check stored token and refresh if needed
        $token = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($settings, $cacheKey) {
            // Double-check cache after acquiring lock
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }

            $accessToken = $settings->get('ups.oauth_access_token');
            $expiresAt = $settings->get('ups.oauth_token_expires_at');

            if (! $accessToken) {
                throw new RuntimeException('UPS OAuth token not found. Please reconnect via Settings.');
            }

            // If token is still valid (with 10-minute buffer), cache and use it
            if ($expiresAt && Carbon::parse($expiresAt)->subMinutes(10)->isFuture()) {
                $ttl = Carbon::parse($expiresAt)->subMinutes(10)->diffInSeconds(now());
                Cache::put($cacheKey, $accessToken, (int) $ttl);

                return $accessToken;
            }

            // Token expired — refresh it
            return static::refreshOAuthToken($settings, $cacheKey);
        });

        $connector->authenticate(new TokenAuthenticator($token));

        return $connector;
    }

    /**
     * Refresh the OAuth access token via the broker.
     */
    private static function refreshOAuthToken(SettingsService $settings, string $cacheKey): string
    {
        $data = app(OAuthService::class)->refreshToken('ups');

        $newAccessToken = $data['access_token'] ?? null;

        if (! $newAccessToken) {
            throw new RuntimeException('UPS token refresh response missing access_token.');
        }

        // Cache with 10-minute buffer
        $expiresIn = (int) ($data['expires_in'] ?? 14400);
        $cacheTtl = max($expiresIn - 600, 60);
        Cache::put($cacheKey, $newAccessToken, $cacheTtl);

        return $newAccessToken;
    }
}
