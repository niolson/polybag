<?php

namespace App\Services;

use App\Enums\AppMode;
use App\Services\Carriers\CarrierRegistry;

/**
 * Which credential fields the connection and carrier account forms show on this
 * install. Dev shows every field. Elsewhere a field is shown only when it is the
 * way in: app credentials when the broker cannot connect the account in the active
 * environment, and only the FedEx fields for that environment.
 *
 * Hiding a field never clears it: a hidden secret that is already stored is kept.
 */
class CredentialFieldVisibility
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Shopify's and Amazon's app credentials, and Amazon's refresh token.
     */
    public function showsConnectionAppCredentials(): bool
    {
        return $this->isDev() || ! $this->oauth->isBrokerConfigured();
    }

    /**
     * A carrier account's "Advanced / API App Credentials" section.
     */
    public function showsCarrierAppCredentials(string $carrierName): bool
    {
        return $this->isDev()
            || ! $this->oauth->isBrokerConfigured()
            || ! CarrierRegistry::connectsThroughBroker($carrierName, $this->settings->isSandboxMode());
    }

    /**
     * A USPS or UPS account's OAuth connect action and status. Hidden where the
     * broker's OAuth cannot connect it, such as in Demo, which forces sandbox.
     * Without a broker it stays, disabled, to say why.
     */
    public function offersCarrierOAuth(string $carrierName): bool
    {
        return $this->isDev()
            || CarrierRegistry::connectsThroughBroker($carrierName, $this->settings->isSandboxMode());
    }

    /**
     * FedEx's account number and app credentials for one environment. Outside Dev
     * the environment is fixed by the mode, so only its own fields are shown.
     *
     * @param  'production'|'sandbox'  $environment
     */
    public function showsFedexEnvironment(string $environment): bool
    {
        return $this->isDev() || ($environment === 'sandbox') === $this->settings->isSandboxMode();
    }

    private function isDev(): bool
    {
        return AppMode::current() === AppMode::Dev;
    }
}
