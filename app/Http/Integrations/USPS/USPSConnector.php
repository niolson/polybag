<?php

namespace App\Http\Integrations\USPS;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Connector;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;
use Saloon\Traits\Plugins\HasTimeout;

class USPSConnector extends Connector
{
    use ClientCredentialsGrant;
    use HasCachedAuthentication;
    use HasTimeout;

    protected int $connectTimeout = 5;

    public function getRequestTimeout(): float
    {
        return (float) SettingsService::get('carrier_api_timeout', 15);
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
        if (SettingsService::get('sandbox_mode', false)) {
            return config('services.usps.sandbox_url', 'https://apis-tem.usps.com');
        }

        return config('services.usps.base_url', 'https://apis.usps.com');
    }

    protected function defaultOauthConfig(): OAuthConfig
    {
        return OAuthConfig::make()
            ->setClientId(config('services.usps.client_id'))
            ->setClientSecret(config('services.usps.client_secret'))
            ->setDefaultScopes(['addresses', 'domestic-prices', 'international-prices', 'payments', 'labels', 'international-labels', 'shipments', 'scan-forms'])
            ->setTokenEndpoint('/oauth2/v3/token');
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        return 'usps_authenticator';
    }

    /**
     * @deprecated Use getAuthenticatedConnector() instead
     */
    public static function getUspsConnector(): self
    {
        return self::getAuthenticatedConnector();
    }

    public static function getUspsPaymentAuthorizationToken(): string
    {
        return Cache::get('usps_payment_authorization_token', function () {
            $request = new PaymentAuthorization;
            $request->body()->set([
                'roles' => [
                    [
                        'roleName' => 'PAYER',
                        'CRID' => config('services.usps.crid'),
                        'MID' => config('services.usps.mid'),
                        'manifestMID' => config('services.usps.mid'),
                        'accountType' => 'EPS',
                        'accountNumber' => config('services.usps.crid'),
                    ],
                    [
                        'roleName' => 'LABEL_OWNER',
                        'CRID' => config('services.usps.crid'),
                        'MID' => config('services.usps.mid'),
                        'manifestMID' => config('services.usps.mid'),
                    ],
                ],
            ]);

            $connector = self::getAuthenticatedConnector();
            $response = $connector->send($request);
            $paymentAuthorizationToken = $response->json('paymentAuthorizationToken');

            if (empty($paymentAuthorizationToken)) {
                logger()->error('USPS payment authorization failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('USPS payment authorization returned empty token');
            }

            Cache::put('usps_payment_authorization_token', $paymentAuthorizationToken, now()->addHours(7));

            return $paymentAuthorizationToken;
        });
    }
}
