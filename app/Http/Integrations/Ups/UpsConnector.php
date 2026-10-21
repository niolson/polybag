<?php

namespace App\Http\Integrations\Ups;

use App\Exceptions\Carriers\CarrierUnavailableException;
use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\Concerns\RetriesTransientErrors;
use App\Models\CarrierAccount;
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

    private ?CarrierAccount $carrierAccount = null;

    public static function forAccount(CarrierAccount $account): self
    {
        $instance = new self;
        $instance->carrierAccount = $account;

        return $instance;
    }

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
        $clientId = $this->carrierAccount?->secret('client_id') ?? '';
        $clientSecret = $this->carrierAccount?->secret('client_secret') ?? '';

        return OAuthConfig::make()
            ->setClientId((string) $clientId)
            ->setClientSecret((string) $clientSecret)
            ->setTokenEndpoint('/security/v1/oauth/token');
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        return 'ups_authenticator'.static::sandboxCacheSuffix();
    }

    /**
     * Cache key for an account's client-credentials token, namespaced by environment.
     */
    public static function authenticatorCacheKeyForAccount(int $accountId): string
    {
        return 'ups_authenticator'.static::sandboxCacheSuffix().":{$accountId}";
    }

    /**
     * Get an authenticated connector, using OAuth token if connected via auth code flow,
     * otherwise falling back to client credentials.
     */
    public static function getAuthenticatedConnector(?CarrierAccount $account = null): static
    {
        $authMode = $account?->secret('auth_mode');

        if ($authMode === 'authorization_code') {
            // The broker's UPS OAuth issues production credentials only.
            if (app(SettingsService::class)->get('sandbox_mode', false)) {
                throw new CarrierUnavailableException('UPS', 'UPS is not available in sandbox mode when connected via OAuth. Use a UPS developer app\'s client ID and secret under Advanced / API App Credentials instead.');
            }

            return static::fromOAuthToken($account);
        }

        return static::fromClientCredentials($account);
    }

    /**
     * Client credentials flow — delegates to the HasCachedAuthentication trait.
     */
    private static function fromClientCredentials(?CarrierAccount $account = null): static
    {
        /** @phpstan-ignore new.static */
        $connector = $account ? static::forAccount($account) : new static;
        $cacheKey = $account?->secret('client_id')
            ? static::authenticatorCacheKeyForAccount($account->id)
            : static::getAuthenticatorCacheKey();

        $cached = Cache::get($cacheKey);

        if (! is_array($cached)) {
            $cached = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($connector, $cacheKey): array {
                $recached = Cache::get($cacheKey);
                if (is_array($recached)) {
                    return $recached;
                }

                $authenticator = $connector->getAccessToken();
                $data = static::serializeAuthenticator($authenticator);

                Cache::put(
                    $cacheKey,
                    $data,
                    $authenticator->getExpiresAt()->sub(DateInterval::createFromDateString('10 minutes'))
                );

                return $data;
            });
        }

        $connector->authenticate(static::deserializeAuthenticator($cached));

        return $connector;
    }

    /**
     * OAuth authorization code flow — uses stored token with automatic refresh.
     */
    private static function fromOAuthToken(?CarrierAccount $account = null): static
    {
        /** @phpstan-ignore new.static */
        $connector = $account ? static::forAccount($account) : new static;
        $cacheKey = $account ? "ups_oauth_token:{$account->id}" : 'ups_oauth_token';

        $cachedToken = Cache::get($cacheKey);

        if ($cachedToken) {
            $connector->authenticate(new TokenAuthenticator($cachedToken));

            return $connector;
        }

        $token = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($cacheKey, $account) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }

            $accessToken = $account?->secret('oauth_token');
            $expiresAt = $account?->secret('token_expires_at');

            if (! $accessToken) {
                $context = $account ? "account {$account->id}" : 'the carrier account';
                throw new RuntimeException("UPS OAuth token not found. Please reconnect via {$context}.");
            }

            if ($expiresAt && Carbon::parse($expiresAt)->subMinutes(10)->isFuture()) {
                $ttl = Carbon::parse($expiresAt)->subMinutes(10)->diffInSeconds(now());
                Cache::put($cacheKey, $accessToken, (int) $ttl);

                return $accessToken;
            }

            return static::refreshOAuthToken($cacheKey, $account);
        });

        $connector->authenticate(new TokenAuthenticator($token));

        return $connector;
    }

    /**
     * Refresh the OAuth access token via the broker.
     */
    private static function refreshOAuthToken(string $cacheKey, ?CarrierAccount $account = null): string
    {
        if (! $account) {
            throw new RuntimeException('Cannot refresh UPS token without a carrier account.');
        }

        $data = app(OAuthService::class)->refreshTokenForAccount('ups', $account);

        $newAccessToken = $data['access_token'] ?? null;

        if (! $newAccessToken) {
            throw new RuntimeException('UPS token refresh response missing access_token.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 14400);
        $cacheTtl = max($expiresIn - 600, 60);
        Cache::put($cacheKey, $newAccessToken, $cacheTtl);

        return $newAccessToken;
    }
}
