<?php

namespace App\Http\Integrations\Ups;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Services\SettingsService;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\ClientCredentialsBasicAuthGrant;
use Saloon\Traits\Plugins\HasTimeout;

class UpsConnector extends Connector
{
    use ClientCredentialsBasicAuthGrant;
    use HasCachedAuthentication;
    use HasTimeout;

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
        return OAuthConfig::make()
            ->setClientId(app(SettingsService::class)->get('ups.client_id', config('services.ups.client_id')))
            ->setClientSecret(app(SettingsService::class)->get('ups.client_secret', config('services.ups.client_secret')))
            ->setTokenEndpoint('/security/v1/oauth/token');
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        return 'ups_authenticator';
    }
}
