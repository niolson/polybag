<?php

namespace App\Http\Integrations\Fedex;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\Concerns\RetriesTransientErrors;
use App\Services\FedexMfaAuditService;
use App\Services\SettingsService;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Saloon\Contracts\OAuthAuthenticator;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\OAuth2\GetClientCredentialsTokenRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;
use Saloon\Traits\Plugins\HasTimeout;

class FedexConnector extends Connector
{
    use ClientCredentialsGrant;
    use HasCachedAuthentication;
    use HasTimeout;
    use RetriesTransientErrors {
        handleRetry as handleTransientRetry;
    }

    private bool $retriedAfterUnauthorized = false;

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

    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        if (! $exception instanceof RequestException) {
            return $this->handleTransientRetry($exception, $request);
        }

        if ($exception->getResponse()->status() !== 401) {
            return $this->handleTransientRetry($exception, $request);
        }

        if ($this->retriedAfterUnauthorized || $request instanceof GetClientCredentialsTokenRequest) {
            return false;
        }

        $this->retriedAfterUnauthorized = true;

        return $this->refreshAuthenticatorAfterUnauthorized($request);
    }

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
        ['clientId' => $clientId, 'clientSecret' => $clientSecret] = $this->getParentCredentials();

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
        $hasBroker = filled(config('services.oauth.broker_url'))
            && filled(config('services.oauth.instance_id'))
            && filled(config('services.oauth.broker_secret'));

        if (filled($childKey) && $hasBroker) {
            return $this->getBrokeredChildAccessToken($settings, $returnResponse);
        }

        if (filled($childKey)) {
            return $this->getDirectChildAccessToken($settings, $returnResponse);
        }

        $requestedScopes = $scopes === [] ? $this->oauthConfig()->getDefaultScopes() : $scopes;
        $request = $this->resolveAccessTokenRequest($this->oauthConfig(), $scopes, $scopeSeparator);
        $request = $this->oauthConfig()->invokeRequestModifier($request);

        if (is_callable($requestModifier)) {
            $requestModifier($request);
        }

        $response = $this->send($request);

        app(FedexMfaAuditService::class)->recordExchange(
            filled($childKey) ? 'child-authorization' : 'parent-authorization',
            $this->buildAuthRequestPayload($request, $requestedScopes, 'direct'),
            $this->buildAuthResponsePayload($response),
        );

        if ($returnResponse) {
            return $response;
        }

        $response->throw();

        return $this->createOAuthAuthenticatorFromResponse($response);
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

    private function refreshAuthenticatorAfterUnauthorized(Request $request): bool
    {
        $cacheKey = static::getAuthenticatorCacheKey();

        Cache::forget($cacheKey);

        try {
            $authenticator = $this->requestFreshAuthenticator();
        } catch (\Throwable $exception) {
            logger()->warning('FedEx returned 401 and token refresh failed', [
                'request' => $request::class,
                'cache_key' => $cacheKey,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        Cache::put(
            $cacheKey,
            static::serializeAuthenticator($authenticator),
            $this->cacheUntilBeforeExpiry($authenticator),
        );

        $this->authenticate($authenticator);

        logger()->warning('FedEx returned 401 with cached authenticator; refreshed token and retrying request', [
            'request' => $request::class,
            'cache_key' => $cacheKey,
        ]);

        return true;
    }

    private function requestFreshAuthenticator(): OAuthAuthenticator
    {
        $connector = new self;

        if ($this->hasMockClient()) {
            $connector->withMockClient($this->getMockClient());
        }

        $authenticator = $connector->getAccessToken();

        if (! $authenticator instanceof OAuthAuthenticator) {
            throw new \RuntimeException('FedEx token refresh did not return an OAuth authenticator.');
        }

        return $authenticator;
    }

    private function cacheUntilBeforeExpiry(OAuthAuthenticator $authenticator): DateTimeImmutable
    {
        return ($authenticator->getExpiresAt() ?? new DateTimeImmutable('+1 hour'))
            ->sub(DateInterval::createFromDateString('10 minutes'));
    }

    /**
     * @deprecated Use getAuthenticatedConnector() instead
     */
    public static function getFedexConnector(): self
    {
        return self::getAuthenticatedConnector();
    }

    /**
     * @return ($returnResponse is true ? Response : OAuthAuthenticator)
     */
    private function getBrokeredChildAccessToken(SettingsService $settings, bool $returnResponse): OAuthAuthenticator|Response
    {
        $isSandbox = $settings->get('fedex.child_env') === 'sandbox';
        $brokerUrl = rtrim(config('services.oauth.broker_url'), '/');
        $proxyPath = '/fedex/token';
        $fedexPath = '/oauth/token';
        $instanceId = config('services.oauth.instance_id');
        $secret = config('services.oauth.broker_secret');
        $nonce = Str::random(40);
        $signature = hash_hmac('sha256', "{$fedexPath}:{$instanceId}:{$nonce}", $secret);
        $requestPayload = [
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
            'child_key' => $settings->get('fedex.child_key'),
            'child_secret' => $settings->get('fedex.child_secret'),
            'sandbox' => $isSandbox ? '1' : '0',
        ];

        $response = Http::acceptJson()->asForm()->post($brokerUrl.$proxyPath, $requestPayload);

        app(FedexMfaAuditService::class)->recordExchange(
            'child-authorization',
            [
                'transport' => 'broker-proxy',
                'uri' => $brokerUrl.$proxyPath,
                'body' => $requestPayload,
            ],
            [
                'status' => $response->status(),
                'body' => $response->json() ?? ['body' => $response->body()],
            ],
        );

        $response->throw();

        $data = $response->json();
        $expiresAt = isset($data['expires_in'])
            ? new DateTimeImmutable('+'.$data['expires_in'].' seconds')
            : null;

        return new AccessTokenAuthenticator($data['access_token'], null, $expiresAt);
    }

    /**
     * @return ($returnResponse is true ? Response : OAuthAuthenticator)
     */
    private function getDirectChildAccessToken(SettingsService $settings, bool $returnResponse): OAuthAuthenticator|Response
    {
        ['clientId' => $clientId, 'clientSecret' => $clientSecret] = $this->getParentCredentials();
        $uri = rtrim($this->resolveBaseUrl(), '/').'/oauth/token';

        $requestPayload = [
            'grant_type' => 'csp_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'child_key' => $settings->get('fedex.child_key'),
            'child_secret' => $settings->get('fedex.child_secret'),
        ];

        $response = Http::acceptJson()->asForm()->post($uri, $requestPayload);

        app(FedexMfaAuditService::class)->recordExchange(
            'child-authorization',
            [
                'transport' => 'direct',
                'uri' => $uri,
                'body' => $requestPayload,
            ],
            [
                'status' => $response->status(),
                'body' => $response->json() ?? ['body' => $response->body()],
            ],
        );

        $response->throw();

        $data = $response->json();
        $expiresAt = isset($data['expires_in'])
            ? new DateTimeImmutable('+'.$data['expires_in'].' seconds')
            : null;

        $authenticator = new AccessTokenAuthenticator($data['access_token'], null, $expiresAt);

        if ($returnResponse) {
            return $authenticator;
        }

        return $authenticator;
    }

    /**
     * @param  array<string>  $requestedScopes
     * @return array<string, mixed>
     */
    private function buildAuthRequestPayload(Request $request, array $requestedScopes, string $transport): array
    {
        return [
            'transport' => $transport,
            'uri' => rtrim($this->resolveBaseUrl(), '/').$request->resolveEndpoint(),
            'requested_scopes' => $requestedScopes,
            'body' => $request->body()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAuthResponsePayload(Response $response): array
    {
        return [
            'status' => $response->status(),
            'body' => $response->json() ?? ['body' => $response->body()],
        ];
    }

    /**
     * @return array{clientId: string, clientSecret: string}
     */
    private function getParentCredentials(): array
    {
        $settings = app(SettingsService::class);
        $isSandbox = filled($settings->get('fedex.child_key'))
            ? $settings->get('fedex.child_env') === 'sandbox'
            : (bool) $settings->get('sandbox_mode', false);

        if ($isSandbox) {
            return [
                'clientId' => (string) $settings->get('fedex.sandbox_api_key', config('services.fedex.sandbox_api_key', '')),
                'clientSecret' => (string) $settings->get('fedex.sandbox_api_secret', config('services.fedex.sandbox_api_secret', '')),
            ];
        }

        return [
            'clientId' => (string) $settings->get('fedex.api_key', config('services.fedex.api_key', '')),
            'clientSecret' => (string) $settings->get('fedex.api_secret', config('services.fedex.api_secret', '')),
        ];
    }
}
