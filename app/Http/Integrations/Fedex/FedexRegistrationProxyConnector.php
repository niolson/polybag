<?php

namespace App\Http\Integrations\Fedex;

use App\Services\SettingsService;
use Illuminate\Support\Str;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

class FedexRegistrationProxyConnector extends Connector
{
    /**
     * Maps FedEx API paths to polybag-connect proxy paths.
     */
    private const PROXY_PATH_MAP = [
        '/registration/v2/address/keysgeneration' => '/fedex/registration/validate-address',
        '/registration/v2/customerkeys/pingeneration' => '/fedex/registration/send-pin',
        '/registration/v2/pin/keysgeneration' => '/fedex/registration/verify-pin',
        '/registration/v2/invoice/keysgeneration' => '/fedex/registration/verify-invoice',
        '/track/v1/trackingnumbers' => '/fedex/track',
    ];

    public function resolveBaseUrl(): string
    {
        return rtrim(config('services.oauth.broker_url'), '/');
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $fedexPath = $pendingRequest->getRequest()->resolveEndpoint();
        $proxyPath = self::PROXY_PATH_MAP[$fedexPath] ?? $fedexPath;

        $pendingRequest->setUrl($this->resolveBaseUrl().$proxyPath);

        $instanceId = config('services.oauth.instance_id');
        $secret = config('services.oauth.broker_secret');
        $nonce = Str::random(40);
        $signature = hash_hmac('sha256', "{$fedexPath}:{$instanceId}:{$nonce}", $secret);

        $isSandbox = app(SettingsService::class)->get('sandbox_mode', false);

        $body = $pendingRequest->body()->all();
        $body['instance_id'] = $instanceId;
        $body['nonce'] = $nonce;
        $body['signature'] = $signature;
        if ($isSandbox) {
            $body['sandbox'] = true;
        }
        $pendingRequest->body()->set($body);
    }
}
