<?php

namespace App\Http\Integrations\Fedex;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\Concerns\RetriesTransientErrors;
use App\Models\CarrierAccount;
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

    private ?CarrierAccount $carrierAccount = null;

    protected int $connectTimeout = 5;

    public static function forAccount(CarrierAccount $account): self
    {
        $instance = new self;
        $instance->carrierAccount = $account;

        return $instance;
    }

    /**
     * Override trait's getAuthenticatedConnector to support per-account credentials.
     */
    public static function getAuthenticatedConnector(?CarrierAccount $account = null): static
    {
        /** @phpstan-ignore new.static */
        $connector = $account ? static::forAccount($account) : new static;
        $cacheKey = $connector->resolveAuthenticatorCacheKey();

        $cached = Cache::get($cacheKey);

        if (! is_array($cached)) {
            $cached = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($connector, $cacheKey): array {
                $recached = Cache::get($cacheKey);
                if (is_array($recached)) {
                    return $recached;
                }

                $authenticator = $connector->getAccessToken();

                if (! $authenticator instanceof OAuthAuthenticator) {
                    throw new \RuntimeException('FedEx token acquisition did not return an OAuth authenticator.');
                }

                $data = static::serializeAuthenticator($authenticator);

                Cache::put($cacheKey, $data, $connector->cacheUntilBeforeExpiry($authenticator));

                return $data;
            });
        }

        $connector->authenticate(static::deserializeAuthenticator($cached));

        return $connector;
    }

    private function resolveAuthenticatorCacheKey(): string
    {
        $childKey = $this->carrierAccount?->secret('child_key');

        if ($this->usesChildCredentials()) {
            $env = $this->carrierAccount->credential('child_env') ?? 'production';

            return 'fedex_authenticator_child_'.$env.'_'.hash('sha256', $childKey);
        }

        return $this->carrierAccount
            ? static::authenticatorCacheKeyForAccount($this->carrierAccount->id)
            : static::getAuthenticatorCacheKey();
    }

    public static function authenticatorCacheKeyForAccount(int $accountId): string
    {
        return static::getAuthenticatorCacheKey().":{$accountId}";
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
        return $this->usesSandbox()
            ? config('services.fedex.sandbox_url', 'https://apis-sandbox.fedex.com')
            : config('services.fedex.base_url', 'https://apis.fedex.com');
    }

    public function usesSandbox(): bool
    {
        return app(SettingsService::class)->isSandboxMode();
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
     * Route through polybag-connect when child credentials belong to the active
     * environment; otherwise use that environment's direct client credentials.
     */
    public function getAccessToken(
        array $scopes = [],
        string $scopeSeparator = ' ',
        bool $returnResponse = false,
        ?callable $requestModifier = null,
    ): OAuthAuthenticator|Response {
        $childKey = $this->carrierAccount?->secret('child_key');

        if ($this->usesChildCredentials()) {
            // Child credentials (OAuth-provisioned) require the broker — it holds the parent
            // CSP key needed to exchange child_key/child_secret for an access token.
            $hasBroker = filled(config('services.oauth.broker_url'))
                && filled(config('services.oauth.instance_id'))
                && filled(config('services.oauth.broker_secret'));

            if ($hasBroker) {
                return $this->getBrokeredChildAccessToken($childKey);
            }

            return $this->getDirectChildAccessToken($childKey);
        }

        $requestedScopes = $scopes === [] ? $this->oauthConfig()->getDefaultScopes() : $scopes;
        $request = $this->resolveAccessTokenRequest($this->oauthConfig(), $scopes, $scopeSeparator);
        $request = $this->oauthConfig()->invokeRequestModifier($request);

        if (is_callable($requestModifier)) {
            $requestModifier($request);
        }

        $response = $this->send($request);

        app(FedexMfaAuditService::class)->recordExchange(
            'parent-authorization',
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
        $isSandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);

        return $isSandbox ? 'fedex_authenticator_sandbox' : 'fedex_authenticator';
    }

    private function refreshAuthenticatorAfterUnauthorized(Request $request): bool
    {
        $cacheKey = $this->resolveAuthenticatorCacheKey();

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
        $connector = $this->carrierAccount ? self::forAccount($this->carrierAccount) : new self;

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

    private function getBrokeredChildAccessToken(string $childKey): OAuthAuthenticator
    {
        $childSecret = $this->carrierAccount?->secret('child_secret');
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
            'child_key' => $childKey,
            'child_secret' => $childSecret,
            'sandbox' => $this->usesSandbox() ? '1' : '0',
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

    private function getDirectChildAccessToken(string $childKey): OAuthAuthenticator
    {
        $childSecret = $this->carrierAccount?->secret('child_secret');
        ['clientId' => $clientId, 'clientSecret' => $clientSecret] = $this->getParentCredentials();
        $uri = rtrim($this->resolveBaseUrl(), '/').'/oauth/token';

        $requestPayload = [
            'grant_type' => 'csp_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'child_key' => $childKey,
            'child_secret' => $childSecret,
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

        return new AccessTokenAuthenticator($data['access_token'], null, $expiresAt);
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
        if ($this->usesSandbox()) {
            return [
                'clientId' => (string) ($this->carrierAccount?->secret('sandbox_api_key') ?? ''),
                'clientSecret' => (string) ($this->carrierAccount?->secret('sandbox_api_secret') ?? ''),
            ];
        }

        return [
            'clientId' => (string) ($this->carrierAccount?->secret('api_key') ?? ''),
            'clientSecret' => (string) ($this->carrierAccount?->secret('api_secret') ?? ''),
        ];
    }

    private function usesChildCredentials(): bool
    {
        return $this->carrierAccount?->hasFedexChildCredentialsForActiveEnvironment() ?? false;
    }
}
