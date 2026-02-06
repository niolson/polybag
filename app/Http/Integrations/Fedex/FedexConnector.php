<?php

namespace App\Http\Integrations\Fedex;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Services\SettingsService;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;

class FedexConnector extends Connector
{
    use ClientCredentialsGrant;
    use HasCachedAuthentication;

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
        if (SettingsService::get('sandbox_mode', false)) {
            return config('services.fedex.sandbox_url', 'https://apis-sandbox.fedex.com');
        }

        return config('services.fedex.base_url', 'https://apis.fedex.com');
    }

    protected function defaultOauthConfig(): OAuthConfig
    {
        return OAuthConfig::make()
            ->setClientId(config('services.fedex.api_key'))
            ->setClientSecret(config('services.fedex.api_secret'))
            ->setTokenEndpoint('/oauth/token');
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        return 'fedex_authenticator';
    }

    /**
     * @deprecated Use getAuthenticatedConnector() instead
     */
    public static function getFedexConnector(): self
    {
        return self::getAuthenticatedConnector();
    }
}
