<?php

namespace App\Http\Integrations\USPS;

use App\Contracts\OAuthProvider;

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
}
