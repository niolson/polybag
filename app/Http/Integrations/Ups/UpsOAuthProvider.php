<?php

namespace App\Http\Integrations\Ups;

use App\Contracts\OAuthProvider;

class UpsOAuthProvider implements OAuthProvider
{
    public function getKey(): string
    {
        return 'ups';
    }

    public function getDisplayName(): string
    {
        return 'UPS';
    }

    public function getSupportedAuthModes(): array
    {
        return ['client_credentials', 'authorization_code'];
    }

    public function getTokenSettingsKey(): string
    {
        return 'ups.oauth_access_token';
    }

    public function getRefreshTokenSettingsKey(): ?string
    {
        return 'ups.oauth_refresh_token';
    }

    public function revokeToken(string $accessToken): void
    {
        // UPS does not provide a token revocation endpoint
    }

    public function getBrokerParams(): array
    {
        return [];
    }
}
