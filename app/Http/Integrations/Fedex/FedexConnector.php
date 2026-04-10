<?php

namespace App\Http\Integrations\Fedex;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\Concerns\RetriesTransientErrors;
use App\Services\SettingsService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Saloon\Contracts\OAuthAuthenticator;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;
use Saloon\Traits\Plugins\HasTimeout;

class FedexConnector extends Connector
{
    use ClientCredentialsGrant {
        getAccessToken as getClientCredentialsAccessToken;
    }
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
        $settings = app(SettingsService::class);

        // If child credentials are active, use the environment they were provisioned in.
        // Otherwise fall back to the global sandbox_mode toggle.
        $isSandbox = filled($settings->get('fedex.child_key'))
            ? $settings->get('fedex.child_env') === 'sandbox'
            : (bool) $settings->get('sandbox_mode', false);

        if ($isSandbox) {
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

    /**
     * Override token acquisition to route through polybag-connect proxy when
     * child credentials are present, so the parent key/secret stays server-side.
     */
    public function getAccessToken(
        array $scopes = [],
        string $scopeSeparator = ' ',
        bool $returnResponse = false,
        ?callable $requestModifier = null,
    ): OAuthAuthenticator|Response {
        $settings = app(SettingsService::class);
        $childKey = $settings->get('fedex.child_key');

        if (! filled($childKey)) {
            return $this->getClientCredentialsAccessToken($scopes, $scopeSeparator, $returnResponse, $requestModifier);
        }

        $isSandbox = $settings->get('fedex.child_env') === 'sandbox';
        $brokerUrl = rtrim(config('services.oauth.broker_url'), '/');
        $proxyPath = '/fedex/token';
        $fedexPath = '/oauth/token';
        $instanceId = config('services.oauth.instance_id');
        $secret = config('services.oauth.broker_secret');
        $nonce = Str::random(40);
        $signature = hash_hmac('sha256', "{$fedexPath}:{$instanceId}:{$nonce}", $secret);

        $response = Http::acceptJson()->asForm()->post($brokerUrl.$proxyPath, [
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
            'child_key' => $childKey,
            'child_secret' => $settings->get('fedex.child_secret'),
            'sandbox' => $isSandbox ? '1' : '0',
        ]);

        $response->throw();

        $data = $response->json();
        $expiresAt = isset($data['expires_in'])
            ? new DateTimeImmutable('+'.$data['expires_in'].' seconds')
            : null;

        return new AccessTokenAuthenticator($data['access_token'], null, $expiresAt);
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        $settings = app(SettingsService::class);
        $childKey = $settings->get('fedex.child_key');

        // When child credentials are active, scope the cache key to the child key + env
        // so we never serve a stale parent-credential token to a child-credential request
        // (or vice versa), and different installations don't share tokens.
        if (filled($childKey)) {
            $env = $settings->get('fedex.child_env', 'production');

            return 'fedex_authenticator_child_'.$env.'_'.hash('sha256', $childKey);
        }

        // No child credentials — use the global sandbox_mode toggle.
        $isSandbox = (bool) $settings->get('sandbox_mode', false);

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
