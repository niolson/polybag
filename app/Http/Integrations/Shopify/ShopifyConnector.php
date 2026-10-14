<?php

namespace App\Http\Integrations\Shopify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Saloon\Http\Connector;

class ShopifyConnector extends Connector
{
    public ?int $tries = 3;

    public ?int $retryInterval = 500;

    public ?bool $useExponentialBackoff = true;

    private const CACHE_KEY_PREFIX = 'shopify_access_token_';

    /**
     * The Shopify Admin API host is always the store's `*.myshopify.com` domain,
     * regardless of any custom storefront domain. Constraining `shop_domain` to
     * this shape prevents an admin-configured value from redirecting the store's
     * OAuth credentials/access token to an arbitrary host. See security review
     * issue 16.
     */
    private const SHOP_DOMAIN_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i';

    public function __construct(
        private readonly string $shopDomain,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $apiVersion = '2026-10',
        private readonly ?string $accessToken = null,
    ) {
        // Empty is allowed through so callers can surface a friendlier
        // "not configured" message; any non-empty value must be a real
        // Shopify host before it is ever used to build a request URL.
        if ($this->shopDomain !== '' && ! preg_match(self::SHOP_DOMAIN_PATTERN, $this->shopDomain)) {
            throw new InvalidArgumentException(
                "Invalid Shopify shop domain [{$this->shopDomain}]: must be a *.myshopify.com host."
            );
        }
    }

    /**
     * Build from a per-source config array. All values (shop_domain, client_id,
     * client_secret, oauth_access_token) are per-source and stored on the DataSource.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings): self
    {
        $accessToken = filled($settings['oauth_access_token'] ?? null)
            ? (string) $settings['oauth_access_token']
            : null;

        return new self(
            shopDomain: (string) ($settings['shop_domain'] ?? ''),
            clientId: (string) ($settings['client_id'] ?? ''),
            clientSecret: (string) ($settings['client_secret'] ?? ''),
            apiVersion: (string) ($settings['api_version'] ?? config('services.shopify.api_version', '2026-10')),
            accessToken: $accessToken,
        );
    }

    public function resolveBaseUrl(): string
    {
        return "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/graphql.json";
    }

    protected function defaultHeaders(): array
    {
        return [
            'X-Shopify-Access-Token' => $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Get a valid access token. The per-source OAuth token takes priority,
     * then the client credentials flow.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $cacheKey = self::CACHE_KEY_PREFIX.md5($this->shopDomain);

        return Cache::get($cacheKey) ?? $this->requestNewToken($cacheKey);
    }

    private function requestNewToken(string $cacheKey): string
    {
        $response = Http::asForm()->post(
            "https://{$this->shopDomain}/admin/oauth/access_token",
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to obtain Shopify access token: '.$response->body()
            );
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? 86399;

        if (! $token) {
            throw new RuntimeException(
                'Shopify token response missing access_token: '.$response->body()
            );
        }

        Cache::put($cacheKey, $token, $expiresIn - 600);

        return $token;
    }
}
