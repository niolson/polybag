<?php

namespace App\Services;

use App\DataTransferObjects\AmazonMarketplaceDiscoveryResult;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OAuthService
{
    /**
     * The record a create page sent into OAuth, until the callback arrives.
     * Written and read by the ReturnsToRecordAwaitingOAuth page concern.
     */
    public const string AWAITING_RECORD_SESSION_KEY = 'oauth_awaiting_record';

    public function __construct(
        private readonly OAuthProviderRegistry $registry,
        private readonly AmazonMarketplaceDiscoveryService $amazonMarketplaceDiscovery,
    ) {}

    public function isBrokerConfigured(): bool
    {
        return filled(config('services.oauth.broker_url'))
            && filled(config('services.oauth.broker_secret'))
            && filled(config('services.oauth.instance_id'));
    }

    /**
     * Explain a UI affordance that only works with the hosted OAuth broker, and
     * name the direct alternative. Returns null when a broker is configured.
     *
     * The broker holds our carrier and IdP developer credentials, so a self-hoster
     * can never be issued its secret. Never tell the reader to set OAUTH_BROKER_*.
     *
     * @param  string  $alternative  What to do instead, as a full sentence.
     */
    public function brokerlessGuidance(string $alternative): ?string
    {
        if ($this->isBrokerConfigured()) {
            return null;
        }

        return "Guided OAuth needs the hosted OAuth broker, which is not configured. {$alternative} See docs/self-hosting.md.";
    }

    /**
     * Generate the broker authorization URL and store nonce in session.
     */
    public function initiateAuthorization(string $providerKey, ?int $accountId = null): string
    {
        $provider = $this->registry->get($providerKey);

        if (! in_array('authorization_code', $provider->getSupportedAuthModes())) {
            throw new RuntimeException("Provider '{$providerKey}' does not support authorization code flow.");
        }

        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        if (! $this->isBrokerConfigured()) {
            throw new RuntimeException('Guided sign-in is not available on this installation. Enter the credentials directly instead.');
        }

        $nonce = Str::random(40);
        session()->put("oauth_state.{$providerKey}", $nonce);

        if ($accountId) {
            session()->put("oauth_account_id.{$providerKey}", $accountId);
        }

        $returnUrl = config('app.url');
        $signature = hash_hmac('sha256', "{$providerKey}:{$instanceId}:{$returnUrl}::{$nonce}", $brokerSecret);

        $params = array_filter([
            'return_url' => $returnUrl,
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
        ]);

        return rtrim($brokerUrl, '/')."/oauth/{$providerKey}/authorize?".http_build_query($params);
    }

    /**
     * Generate the broker authorization URL for an SSO login flow.
     * The broker will redirect to /auth/sso/{provider}/receive on completion.
     */
    public function initiateSsoAuthorization(string $provider): string
    {
        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        if (! $this->isBrokerConfigured()) {
            throw new RuntimeException('Guided sign-in is not available on this installation. Enter the credentials directly instead.');
        }

        $nonce = Str::random(40);
        session()->put("oauth_state.{$provider}", $nonce);

        $returnUrl = config('app.url');
        $returnPath = "/auth/sso/{$provider}/receive";
        $signature = hash_hmac('sha256', "{$provider}:{$instanceId}:{$returnUrl}:{$returnPath}:{$nonce}", $brokerSecret);

        $params = [
            'return_url' => $returnUrl,
            'return_path' => $returnPath,
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
        ];

        return rtrim($brokerUrl, '/')."/oauth/{$provider}/authorize?".http_build_query($params);
    }

    /**
     * The provider a new USPS or UPS account signs in through: the broker is
     * configured, its OAuth works in the active environment, and the operator
     * entered no client ID of the account's own. The broker's USPS and UPS
     * credentials work against production only, so never in sandbox, which Demo
     * forces. FedEx registers through its own wizard instead, so is never one.
     */
    public function providerForNewCarrierAccount(?string $carrierName, ?string $ownClientId): ?string
    {
        $provider = match ($carrierName) {
            Carrier::USPS => 'usps',
            Carrier::UPS => 'ups',
            default => null,
        };

        if ($provider === null
            || filled($ownClientId)
            || ! $this->isBrokerConfigured()
            || ! CarrierRegistry::connectsThroughBroker($carrierName, app(SettingsService::class)->isSandboxMode())) {
            return null;
        }

        return $provider;
    }

    /**
     * Whether a new account opens the Connect FedEx Account wizard. The wizard
     * drives FedEx's Account Registration API, which only the broker's parent
     * key can authorize, so it needs the broker; an ordinary developer key
     * cannot, and works with its own API key and secret instead.
     */
    public function opensFedexRegistrationForNewAccount(?string $carrierName): bool
    {
        return $carrierName === Carrier::FEDEX && $this->isBrokerConfigured();
    }

    /**
     * The provider a new Shopify or Amazon connection signs in through: the broker
     * is configured and none of the connection's own credentials were entered — a
     * Shopify app Client ID, or an Amazon refresh token. Null when the connection
     * will use what was entered, or has no OAuth path.
     *
     * @param  array<string, mixed>  $settings  The submitted settings, secrets included.
     */
    public function providerForNewDataSource(?string $sourceType, array $settings): ?string
    {
        if (! $this->isBrokerConfigured()) {
            return null;
        }

        return match ($sourceType) {
            ShopifySource::class => blank($settings['client_id'] ?? null) ? 'shopify' : null,
            AmazonSource::class => blank($settings['refresh_token'] ?? null) ? 'sp-api' : null,
            default => null,
        };
    }

    /**
     * Generate the broker authorization URL for a specific DataSource.
     * Stores the DataSource ID in session so the callback can route back to it.
     */
    public function initiateAuthorizationForDataSource(string $providerKey, DataSource $importSource): string
    {
        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        if (! $this->isBrokerConfigured()) {
            throw new RuntimeException('Guided sign-in is not available on this installation. Enter the credentials directly instead.');
        }

        $nonce = Str::random(40);
        session()->put("oauth_state.{$providerKey}", $nonce);
        session()->put("oauth_data_source_id.{$providerKey}", $importSource->id);

        $returnUrl = config('app.url');
        $signature = hash_hmac('sha256', "{$providerKey}:{$instanceId}:{$returnUrl}::{$nonce}", $brokerSecret);

        $brokerParams = $this->getBrokerParamsForDataSource($providerKey, $importSource);

        $params = array_filter([
            'return_url' => $returnUrl,
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
            ...$brokerParams,
        ]);

        return rtrim($brokerUrl, '/')."/oauth/{$providerKey}/authorize?".http_build_query($params);
    }

    /**
     * Handle the broker redirect for a per-DataSource OAuth connection.
     * Tokens are stored in the DataSource settings JSON.
     */
    public function handleReceiveForDataSource(string $providerKey, string $transferCode, DataSource $importSource): ?AmazonMarketplaceDiscoveryResult
    {
        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        $signature = hash_hmac('sha256', $transferCode, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/').'/oauth/claim', [
            'transfer_code' => $transferCode,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            logger()->error('The OAuth broker refused a token claim', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException('The connection could not be completed. Start it again.');
        }

        $data = $response->json();

        if (($data['provider'] ?? null) !== $providerKey) {
            throw new RuntimeException('The connection could not be completed. Start it again.');
        }

        $expectedNonce = session()->pull("oauth_state.{$providerKey}");

        if (empty($expectedNonce) || ! hash_equals($expectedNonce, $data['nonce'] ?? '')) {
            throw new RuntimeException('The connection could not be completed. Start it again.');
        }

        $settings = $importSource->settings ?? [];

        if ($providerKey === 'sp-api') {
            $refreshToken = $data['refresh_token'] ?? null;
            $sellingPartnerId = $data['extra']['selling_partner_id'] ?? null;

            if (empty($refreshToken)) {
                throw new RuntimeException('No Amazon refresh token received from broker.');
            }

            if (empty($sellingPartnerId)) {
                throw new RuntimeException('No Amazon selling partner ID received from broker.');
            }

            $importSource->mergeSecret('refresh_token', $refreshToken);
            $settings['amazon_selling_partner_id'] = $sellingPartnerId;
        } else {
            $accessToken = $data['access_token'] ?? null;

            if (empty($accessToken)) {
                throw new RuntimeException('No access token received from broker.');
            }

            $importSource->mergeSecret('oauth_access_token', $accessToken);
        }

        $settings['oauth_connected_at'] = now()->toIso8601String();
        $settings['oauth_scopes'] = $data['extra']['scope'] ?? $data['scope'] ?? '';
        $settings['auth_mode'] = 'authorization_code';
        $importSource->settings = $settings;
        $importSource->save();

        if ($providerKey === 'sp-api') {
            $accessToken = $data['access_token'] ?? null;
            $refreshToken = $data['refresh_token'] ?? null;

            if (is_string($accessToken) && $accessToken !== '' && is_string($refreshToken) && $refreshToken !== '') {
                $cacheSeconds = max(60, ((int) ($data['expires_in'] ?? 3600)) - 600);
                Cache::put('amazon_sp_api_access_token_'.md5($refreshToken), $accessToken, $cacheSeconds);
            }

            return $this->amazonMarketplaceDiscovery->discover($importSource);
        }

        // Bust the per-shop token cache so the new token is picked up immediately.
        $shopDomain = $settings['shop_domain'] ?? '';
        if ($shopDomain) {
            Cache::forget('shopify_access_token_'.md5($shopDomain));
        }

        return null;
    }

    /**
     * Disconnect an DataSource's OAuth connection, clearing its token fields.
     */
    public function disconnectDataSource(string $providerKey, DataSource $importSource): void
    {
        // Best-effort revocation
        $token = $importSource->secret('oauth_access_token');
        if ($token && $this->registry->has($providerKey)) {
            try {
                $this->registry->get($providerKey)->revokeToken($token);
            } catch (\Throwable) {
            }
        }

        $secrets = $importSource->secret_settings ?? [];
        unset($secrets['oauth_access_token']);

        if ($providerKey === 'sp-api' && ($importSource->settings['auth_mode'] ?? null) === 'authorization_code') {
            Cache::forget('amazon_sp_api_access_token_'.md5((string) ($secrets['refresh_token'] ?? '')));
            unset($secrets['refresh_token']);
        }
        $importSource->secret_settings = $secrets ?: null;

        $settings = $importSource->settings ?? [];
        foreach (['oauth_connected_at', 'oauth_scopes', 'auth_mode', 'amazon_selling_partner_id', 'amazon_marketplaces', 'amazon_marketplaces_synced_at', 'amazon_marketplaces_sync_error'] as $key) {
            unset($settings[$key]);
        }

        $importSource->settings = $settings;
        $importSource->save();

        $shopDomain = $settings['shop_domain'] ?? '';
        if ($shopDomain) {
            Cache::forget('shopify_access_token_'.md5($shopDomain));
        }
    }

    /**
     * Check if an DataSource is connected via OAuth.
     */
    public function isDataSourceConnected(DataSource $importSource): bool
    {
        if (($importSource->settings['auth_mode'] ?? null) !== 'authorization_code') {
            return false;
        }

        if ($importSource->source_type === AmazonSource::class) {
            return filled($importSource->secret('refresh_token'));
        }

        return filled($importSource->secret('oauth_access_token'));
    }

    /**
     * Build provider-specific broker params for a per-DataSource OAuth flow.
     * For Shopify this overrides the shop domain with the per-source value.
     *
     * @return array<string, string>
     */
    private function getBrokerParamsForDataSource(string $providerKey, DataSource $importSource): array
    {
        if ($providerKey === 'shopify') {
            $shopDomain = $importSource->settings['shop_domain'] ?? null;

            if (! is_string($shopDomain) || preg_match(ShopifyConnector::SHOP_DOMAIN_PATTERN, $shopDomain) !== 1) {
                throw new RuntimeException('The connection\'s Shopify shop domain must be a *.myshopify.com host.');
            }

            return [
                'shop' => $shopDomain,
                // Ask for the label-purchase scopes at connect time too, so a
                // source that later enables Shopify Shipping does not need a
                // second trip through consent. Shopify ignores this parameter
                // for apps with declared scopes — see ShopifyLocationSynchronizer
                // — so the Dev Dashboard remains the authority either way.
                'scope' => implode(',', array_unique([
                    ...ShopifySource::REQUIRED_SCOPES,
                    ...ShopifyShippingLabelService::REQUIRED_SCOPES,
                ])),
            ];
        }

        return [];
    }

    /**
     * Handle broker redirect for a per-account OAuth connection.
     * Tokens are stored in the account's secret_credentials instead of global settings.
     */
    public function handleReceiveForAccount(string $providerKey, string $transferCode, CarrierAccount $account): void
    {
        $provider = $this->registry->get($providerKey);

        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        $signature = hash_hmac('sha256', $transferCode, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/').'/oauth/claim', [
            'transfer_code' => $transferCode,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            logger()->error('The OAuth broker refused a token claim', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException('The connection could not be completed. Start it again.');
        }

        $data = $response->json();

        $expectedNonce = session()->pull("oauth_state.{$providerKey}");

        if (empty($expectedNonce) || ! hash_equals($expectedNonce, $data['nonce'] ?? '')) {
            throw new RuntimeException('The connection could not be completed. Start it again.');
        }

        $accessToken = $data['access_token'] ?? null;
        if (empty($accessToken)) {
            throw new RuntimeException('No access token received from broker.');
        }

        $account->mergeSecret('oauth_token', $accessToken);
        $account->mergeSecret('auth_mode', 'authorization_code');

        if (! empty($data['refresh_token'])) {
            $account->mergeSecret('refresh_token', $data['refresh_token']);
        }

        if (! empty($data['expires_in'])) {
            $account->mergeSecret('token_expires_at', now()->addSeconds((int) $data['expires_in'])->toIso8601String());
        }

        if (! empty($data['refresh_token_expires_in'])) {
            $account->mergeSecret('refresh_token_expires_at', now()->addSeconds((int) $data['refresh_token_expires_in'])->toIso8601String());
        }

        $scopes = $data['extra']['scope'] ?? $data['scope'] ?? '';
        $account->mergeCredential('oauth_scopes', $scopes);
        $account->mergeCredential('oauth_connected_at', now()->toIso8601String());

        $account->save();

        if (method_exists($provider, 'afterConnectToAccount')) {
            $provider->afterConnectToAccount($accessToken, $account);
        }
    }

    /**
     * Refresh an expired token for a specific account via the broker.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    public function refreshTokenForAccount(string $providerKey, CarrierAccount $account): array
    {
        $refreshToken = $account->secret('refresh_token');

        if (empty($refreshToken)) {
            throw new RuntimeException("No refresh token stored for account {$account->id}. Please reconnect.");
        }

        $refreshExpiresAt = $account->secret('refresh_token_expires_at');
        if ($refreshExpiresAt && now()->greaterThan($refreshExpiresAt)) {
            throw new RuntimeException("Refresh token expired for account {$account->id}. Please reconnect.");
        }

        $data = $this->refreshToken($providerKey, $refreshToken);

        if (! empty($data['access_token'])) {
            $account->mergeSecret('oauth_token', $data['access_token']);
        }

        if (! empty($data['refresh_token'])) {
            $account->mergeSecret('refresh_token', $data['refresh_token']);
        }

        if (! empty($data['expires_in'])) {
            $account->mergeSecret('token_expires_at', now()->addSeconds((int) $data['expires_in'])->toIso8601String());
        }

        if (! empty($data['refresh_token_expires_in'])) {
            $account->mergeSecret('refresh_token_expires_at', now()->addSeconds((int) $data['refresh_token_expires_in'])->toIso8601String());
        }

        $account->save();

        return $data;
    }

    /**
     * Refresh an access token and persist a rotated refresh token on its data source.
     *
     * @return array{access_token: string, refresh_token?: string|null, expires_in?: int|null, refresh_token_expires_in?: int|null}
     */
    public function refreshTokenForDataSource(string $providerKey, int $dataSourceId): array
    {
        $dataSource = DataSource::find($dataSourceId);

        if (! $dataSource) {
            throw new RuntimeException('This connection no longer exists.');
        }

        $refreshToken = $dataSource->secret('refresh_token');

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new RuntimeException('No refresh token is stored for this connection. Reconnect it.');
        }

        $data = $this->refreshToken($providerKey, $refreshToken);
        $rotatedRefreshToken = $data['refresh_token'] ?? null;

        if (! is_string($rotatedRefreshToken) || $rotatedRefreshToken === '' || hash_equals($refreshToken, $rotatedRefreshToken)) {
            return $data;
        }

        $persistedRefreshToken = DB::transaction(function () use ($dataSourceId, $refreshToken, $rotatedRefreshToken): string {
            $lockedDataSource = DataSource::query()->lockForUpdate()->find($dataSourceId);

            if (! $lockedDataSource) {
                throw new RuntimeException('This connection no longer exists.');
            }

            $storedRefreshToken = $lockedDataSource->secret('refresh_token');

            if (! is_string($storedRefreshToken) || $storedRefreshToken === '') {
                throw new RuntimeException('No refresh token is stored for this connection. Reconnect it.');
            }

            if (hash_equals($refreshToken, $storedRefreshToken)) {
                $lockedDataSource->mergeSecret('refresh_token', $rotatedRefreshToken);
                $lockedDataSource->save();

                return $rotatedRefreshToken;
            }

            return $storedRefreshToken;
        });

        $data['refresh_token'] = $persistedRefreshToken;

        if (! hash_equals($refreshToken, $persistedRefreshToken)) {
            Cache::forget('amazon_sp_api_access_token_'.md5($refreshToken));
        }

        return $data;
    }

    /**
     * Refresh an access token through the shared OAuth broker.
     *
     * @return array{access_token: string, refresh_token?: string|null, expires_in?: int|null, refresh_token_expires_in?: int|null}
     */
    public function refreshToken(string $providerKey, string $refreshToken): array
    {
        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        if (! $this->isBrokerConfigured()) {
            throw new RuntimeException('Guided sign-in is not available on this installation. Enter the credentials directly instead.');
        }

        $signature = hash_hmac('sha256', $refreshToken, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/')."/oauth/{$providerKey}/refresh", [
            'refresh_token' => $refreshToken,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            logger()->error('The OAuth broker refused a token refresh', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException('The connection could not be refreshed. Reconnect it.');
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new RuntimeException('Token refresh response did not include an access token.');
        }

        return $data;
    }

    /**
     * Disconnect an account's OAuth connection, clearing its token fields.
     */
    public function disconnectAccount(CarrierAccount $account, string $providerKey): void
    {
        $provider = $this->registry->get($providerKey);

        $token = $account->secret('oauth_token');
        if ($token) {
            try {
                $provider->revokeToken($token);
            } catch (\Throwable) {
                // Best-effort; continue with local cleanup
            }
        }

        $secrets = $account->secret_credentials ?? [];
        foreach (['oauth_token', 'refresh_token', 'token_expires_at', 'refresh_token_expires_at', 'auth_mode'] as $key) {
            unset($secrets[$key]);
        }
        $account->secret_credentials = $secrets ?: null;

        $creds = $account->credentials ?? [];
        foreach (['oauth_scopes', 'oauth_connected_at'] as $key) {
            unset($creds[$key]);
        }
        $account->credentials = $creds ?: null;

        $account->save();
    }

    /**
     * Check if a specific account is connected via OAuth.
     */
    public function isAccountConnected(CarrierAccount $account): bool
    {
        return ! empty($account->secret('oauth_token'));
    }
}
