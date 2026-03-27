<?php

namespace App\Console\Commands;

use App\Services\OAuthService;
use App\Services\OAuthProviderRegistry;
use Illuminate\Console\Command;

class RefreshOAuthTokens extends Command
{
    protected $signature = 'oauth:refresh';

    protected $description = 'Proactively refresh OAuth tokens for connected providers';

    public function handle(OAuthService $oauthService, OAuthProviderRegistry $registry): int
    {
        foreach ($registry->all() as $key => $provider) {
            if (! $provider->getRefreshTokenSettingsKey()) {
                continue;
            }

            if (! $oauthService->isConnected($key) || $oauthService->getAuthMode($key) !== 'authorization_code') {
                continue;
            }

            try {
                $oauthService->refreshToken($key);
                $this->info("Refreshed {$key} tokens.");
            } catch (\Throwable $e) {
                $this->error("Failed to refresh {$key}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
