<?php

namespace App\Http\Integrations\USPS;

use App\Contracts\OAuthProvider;
use App\Services\SettingsService;

class UspsOAuthProvider implements OAuthProvider
{
    public function getKey(): string
    {
        return 'usps';
    }

    public function getDisplayName(): string
    {
        return 'USPS';
    }

    public function getSupportedAuthModes(): array
    {
        return ['client_credentials', 'authorization_code'];
    }

    public function getTokenSettingsKey(): string
    {
        return 'usps.oauth_access_token';
    }

    public function getRefreshTokenSettingsKey(): ?string
    {
        return 'usps.oauth_refresh_token';
    }

    public function revokeToken(string $accessToken): void
    {
        // USPS does not provide a token revocation endpoint
    }

    public function getBrokerParams(): array
    {
        return [];
    }

    /**
     * Decode the JWT and auto-populate CRID, MID, and EPS account from the
     * account info USPS embeds in the access token.
     */
    public function afterConnect(string $accessToken, SettingsService $settings): void
    {
        $parts = explode('.', $accessToken);

        if (count($parts) !== 3) {
            return;
        }

        $payload = json_decode(
            base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)),
            true
        );

        if (! is_array($payload)) {
            return;
        }

        $group = $this->getKey();

        if (! empty($payload['crid'])) {
            $settings->set('usps.crid', (string) $payload['crid'], group: $group);
        }

        // Use the first (master) MID from mail_owners
        $midsRaw = $payload['mail_owners'][0]['mids'] ?? null;
        if ($midsRaw) {
            $masterMid = trim(explode(',', (string) $midsRaw)[0]);
            $settings->set('usps.mid', $masterMid, group: $group);
        }

        // EPS payment account number (distinct from CRID)
        $epsAccount = $payload['payment_accounts']['accounts'] ?? null;
        if ($epsAccount) {
            $settings->set('usps.eps_account', (string) $epsAccount, group: $group);
        }

        if (! empty($payload['company_name'])) {
            $settings->set('usps.company_name', $payload['company_name'], group: $group);
        }
    }
}
