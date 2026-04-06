<?php

namespace App\Http\Integrations\USPS;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\Concerns\RetriesTransientErrors;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Services\OAuthService;
use App\Services\SettingsService;
use Carbon\Carbon;
use DateInterval;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;
use Saloon\Traits\Plugins\HasTimeout;

class USPSConnector extends Connector
{
    use ClientCredentialsGrant;
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
            return config('services.usps.sandbox_url', 'https://apis-tem.usps.com');
        }

        return config('services.usps.base_url', 'https://apis.usps.com');
    }

    protected function defaultOauthConfig(): OAuthConfig
    {
        $settings = app(SettingsService::class);

        return OAuthConfig::make()
            ->setClientId((string) $settings->get('usps.client_id', ''))
            ->setClientSecret((string) $settings->get('usps.client_secret', ''))
            ->setDefaultScopes(['addresses', 'domestic-prices', 'international-prices', 'payments', 'labels', 'international-labels', 'shipments', 'scan-forms'])
            ->setTokenEndpoint('/oauth2/v3/token');
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        return 'usps_authenticator';
    }

    /**
     * Get an authenticated connector, using OAuth token if connected via auth code flow,
     * otherwise falling back to client credentials.
     */
    public static function getAuthenticatedConnector(): static
    {
        $settings = app(SettingsService::class);

        if ($settings->get('usps.auth_mode') === 'authorization_code') {
            return static::fromOAuthToken($settings);
        }

        // Client credentials flow (HasCachedAuthentication trait behavior)
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
        $cacheKey = 'usps_oauth_token';

        $cachedToken = Cache::get($cacheKey);

        if ($cachedToken) {
            $connector->authenticate(new TokenAuthenticator($cachedToken));

            return $connector;
        }

        $token = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($settings, $cacheKey) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }

            $accessToken = $settings->get('usps.oauth_access_token');
            $expiresAt = $settings->get('usps.oauth_token_expires_at');

            if (! $accessToken) {
                throw new RuntimeException('USPS OAuth token not found. Please reconnect via Settings.');
            }

            if ($expiresAt && Carbon::parse($expiresAt)->subMinutes(10)->isFuture()) {
                $ttl = Carbon::parse($expiresAt)->subMinutes(10)->diffInSeconds(now());
                Cache::put($cacheKey, $accessToken, (int) $ttl);

                return $accessToken;
            }

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
        $data = app(OAuthService::class)->refreshToken('usps');

        $newAccessToken = $data['access_token'] ?? null;

        if (! $newAccessToken) {
            throw new RuntimeException('USPS token refresh response missing access_token.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 14400);
        $cacheTtl = max($expiresIn - 600, 60);
        Cache::put($cacheKey, $newAccessToken, $cacheTtl);

        return $newAccessToken;
    }

    /**
     * @deprecated Use getAuthenticatedConnector() instead
     */
    public static function getUspsConnector(): self
    {
        return self::getAuthenticatedConnector();
    }

    public static function getUspsPaymentAuthorizationToken(): string
    {
        return Cache::get('usps_payment_authorization_token', function () {
            $request = new PaymentAuthorization;
            $request->body()->set([
                'roles' => [
                    [
                        'roleName' => 'PAYER',
                        'CRID' => app(SettingsService::class)->get('usps.crid'),
                        'MID' => app(SettingsService::class)->get('usps.mid'),
                        'manifestMID' => app(SettingsService::class)->get('usps.mid'),
                        'accountType' => 'EPS',
                        'accountNumber' => app(SettingsService::class)->get('usps.crid'),
                    ],
                    [
                        'roleName' => 'LABEL_OWNER',
                        'CRID' => app(SettingsService::class)->get('usps.crid'),
                        'MID' => app(SettingsService::class)->get('usps.mid'),
                        'manifestMID' => app(SettingsService::class)->get('usps.mid'),
                    ],
                ],
            ]);

            $connector = self::getAuthenticatedConnector();
            $response = $connector->send($request);
            $paymentAuthorizationToken = $response->json('paymentAuthorizationToken');

            if (empty($paymentAuthorizationToken)) {
                logger()->error('USPS payment authorization failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new RuntimeException('USPS payment authorization returned empty token');
            }

            Cache::put('usps_payment_authorization_token', $paymentAuthorizationToken, now()->addHours(7));

            return $paymentAuthorizationToken;
        });
    }
}
