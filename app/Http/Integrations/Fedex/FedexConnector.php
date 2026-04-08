<?php

namespace App\Http\Integrations\Fedex;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\Concerns\RetriesTransientErrors;
use App\Services\SettingsService;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;
use Saloon\Traits\Plugins\HasTimeout;

class FedexConnector extends Connector
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
            return config('services.fedex.sandbox_url', 'https://apis-sandbox.fedex.com');
        }

        return config('services.fedex.base_url', 'https://apis.fedex.com');
    }

    protected function defaultOauthConfig(): OAuthConfig
    {
        $settings = app(SettingsService::class);
        $isSandbox = $settings->get('sandbox_mode', false);

        // Child credentials (provisioned via Account Registration) take priority.
        // Fall back to environment-appropriate parent credentials.
        if (filled($settings->get('fedex.child_key'))) {
            $clientId = $settings->get('fedex.child_key');
            $clientSecret = $settings->get('fedex.child_secret');
        } elseif ($isSandbox) {
            $clientId = $settings->get('fedex.sandbox_api_key', config('services.fedex.sandbox_api_key', ''));
            $clientSecret = $settings->get('fedex.sandbox_api_secret', config('services.fedex.sandbox_api_secret', ''));
        } else {
            $clientId = $settings->get('fedex.api_key', config('services.fedex.api_key', ''));
            $clientSecret = $settings->get('fedex.api_secret', config('services.fedex.api_secret', ''));
        }

        return OAuthConfig::make()
            ->setClientId((string) $clientId)
            ->setClientSecret((string) $clientSecret)
            ->setTokenEndpoint('/oauth/token');
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        // Separate cache keys for sandbox vs production — FedEx issues different
        // client credentials per environment, so tokens are not interchangeable.
        $isSandbox = app(SettingsService::class)->get('sandbox_mode', false);

        return $isSandbox ? 'fedex_authenticator_sandbox' : 'fedex_authenticator';
    }

    /**
     * @deprecated Use getAuthenticatedConnector() instead
     */
    public static function getFedexConnector(): self
    {
        return self::getAuthenticatedConnector();
    }
}
