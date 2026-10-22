<?php

namespace App\Http\Integrations\Amazon;

use App\Enums\AmazonSpApiRegion;
use App\Services\OAuthService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

class AmazonSpApiConnector extends Connector
{
    public ?int $tries = 3;

    public ?int $retryInterval = 500;

    public ?bool $useExponentialBackoff = true;

    private const CACHE_KEY_PREFIX = 'amazon_sp_api_access_token_';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sandboxUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private string $refreshToken,
        private readonly string $authMode,
        private readonly ?int $dataSourceId,
    ) {}

    /**
     * The connection whose credentials this connector sends with, when it was
     * built for one. A reply is that account's answer, whatever the connection
     * has become since the request left.
     */
    public function dataSourceId(): ?int
    {
        return $this->dataSourceId;
    }

    /**
     * Build from a per-source config array. All credentials (client_id, client_secret,
     * refresh_token) are per-source and stored on the DataSource.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings): self
    {
        return new self(
            baseUrl: config('services.amazon.base_url') ?? 'https://sellingpartnerapi-na.amazon.com',
            sandboxUrl: config('services.amazon.sandbox_url') ?? AmazonSpApiRegion::NorthAmerica->sandboxUrl(),
            clientId: (string) ($settings['client_id'] ?? ''),
            clientSecret: (string) ($settings['client_secret'] ?? ''),
            refreshToken: (string) ($settings['refresh_token'] ?? ''),
            authMode: (string) ($settings['auth_mode'] ?? 'manual'),
            dataSourceId: isset($settings['_data_source_id']) ? (int) $settings['_data_source_id'] : null,
        );
    }

    /**
     * The host a request starts from: the one a request that declares no kind
     * goes to. {@see self::boot()} then moves each request to the host
     * {@see AmazonSpApiEnvironment} gives it.
     */
    public function resolveBaseUrl(): string
    {
        return $this->inSandbox() ? $this->sandboxUrl : $this->baseUrl;
    }

    /**
     * Resolve the host per request. In Demo a request that only reads the
     * seller's data goes to production while postage stays on the sandbox
     * ({@see AmazonSpApiEnvironment}), so one connector serves both.
     *
     * Amazon also scopes sandbox test cases by region, so a sandbox request
     * that says which region its test cases live in is moved there.
     * Production is North America for every API and marketplace we call
     * (`sellingpartnerapi-na` serves the US, Canada, Mexico and Brazil), so
     * nothing moves off it there.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        $request = $pendingRequest->getRequest();

        $host = match (true) {
            ! app(AmazonSpApiEnvironment::class)->usesSandbox($request) => $this->baseUrl,
            $request instanceof DeclaresSandboxRegion => $request->sandboxRegion()->sandboxUrl(),
            default => $this->sandboxUrl,
        };

        $pendingRequest->setUrl(rtrim($host, '/').'/'.ltrim($request->resolveEndpoint(), '/'));
    }

    private function inSandbox(): bool
    {
        return app(SettingsService::class)->isSandboxMode();
    }

    protected function defaultHeaders(): array
    {
        return [
            'x-amz-access-token' => $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    }

    private function getAccessToken(): string
    {
        $cacheKey = self::CACHE_KEY_PREFIX.md5($this->refreshToken);

        return Cache::get($cacheKey) ?? $this->requestNewToken();
    }

    private function requestNewToken(): string
    {
        if ($this->authMode === 'authorization_code') {
            $data = $this->dataSourceId
                ? app(OAuthService::class)->refreshTokenForDataSource('sp-api', $this->dataSourceId)
                : app(OAuthService::class)->refreshToken('sp-api', $this->refreshToken);
        } else {
            $response = Http::asForm()->post(
                'https://api.amazon.com/auth/o2/token',
                [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refreshToken,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Failed to obtain Amazon SP-API access token: '.$response->body()
                );
            }

            $data = $response->json();
        }

        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Amazon token response missing access_token.');
        }

        $cacheSeconds = max(60, ((int) ($data['expires_in'] ?? 3600)) - 600);
        if (is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '') {
            $this->refreshToken = $data['refresh_token'];
        }

        Cache::put(self::CACHE_KEY_PREFIX.md5($this->refreshToken), $token, $cacheSeconds);

        return $token;
    }
}
