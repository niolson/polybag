<?php

namespace App\Http\Integrations\Amazon;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Saloon\Http\Connector;

class AmazonSpApiConnector extends Connector
{
    public ?int $tries = 3;

    public ?int $retryInterval = 500;

    public ?bool $useExponentialBackoff = true;

    private const CACHE_KEY = 'amazon_sp_api_access_token';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sandboxUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: config('services.amazon.base_url') ?? 'https://sellingpartnerapi-na.amazon.com',
            sandboxUrl: config('services.amazon.sandbox_url') ?? 'https://sandbox.sellingpartnerapi-na.amazon.com',
            clientId: config('services.amazon.client_id') ?? '',
            clientSecret: config('services.amazon.client_secret') ?? '',
            refreshToken: config('services.amazon.refresh_token') ?? '',
        );
    }

    public function resolveBaseUrl(): string
    {
        $sandbox = SettingsService::get('sandbox_mode', false);

        return $sandbox ? $this->sandboxUrl : $this->baseUrl;
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
        return Cache::get(self::CACHE_KEY) ?? $this->requestNewToken();
    }

    private function requestNewToken(): string
    {
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
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new RuntimeException(
                'Amazon token response missing access_token: '.$response->body()
            );
        }

        // Token lasts 1 hour; cache for 50 minutes
        Cache::put(self::CACHE_KEY, $token, 3000);

        return $token;
    }
}
