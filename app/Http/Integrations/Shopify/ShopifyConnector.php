<?php

namespace App\Http\Integrations\Shopify;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Saloon\Http\Connector;

class ShopifyConnector extends Connector
{
    public ?int $tries = 3;

    public ?int $retryInterval = 500;

    public ?bool $useExponentialBackoff = true;

    private const CACHE_KEY = 'shopify_access_token';

    public function __construct(
        private readonly string $shopDomain,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $apiVersion = '2025-01',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            shopDomain: app(SettingsService::class)->get('shopify.shop_domain', config('services.shopify.shop_domain') ?? ''),
            clientId: app(SettingsService::class)->get('shopify.client_id', config('services.shopify.client_id') ?? ''),
            clientSecret: app(SettingsService::class)->get('shopify.client_secret', config('services.shopify.client_secret') ?? ''),
            apiVersion: app(SettingsService::class)->get('shopify.api_version', config('services.shopify.api_version') ?? '2025-01'),
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
     * Get a valid access token, using cached token or requesting a new one.
     */
    private function getAccessToken(): string
    {
        return Cache::get(self::CACHE_KEY) ?? $this->requestNewToken();
    }

    /**
     * Request a new access token via the client credentials grant.
     */
    private function requestNewToken(): string
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

        // Cache with 10-minute buffer before expiry
        Cache::put(self::CACHE_KEY, $token, $expiresIn - 600);

        return $token;
    }
}
