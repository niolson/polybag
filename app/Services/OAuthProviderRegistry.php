<?php

namespace App\Services;

use App\Contracts\OAuthProvider;
use RuntimeException;

class OAuthProviderRegistry
{
    /**
     * @var array<string, OAuthProvider>
     */
    private array $providers = [];

    /**
     * Register an OAuth provider.
     */
    public function register(OAuthProvider $provider): void
    {
        $this->providers[$provider->getKey()] = $provider;
    }

    /**
     * Get a registered provider by key.
     *
     * @throws RuntimeException if provider not found
     */
    public function get(string $key): OAuthProvider
    {
        if (! isset($this->providers[$key])) {
            throw new RuntimeException("OAuth provider '{$key}' is not registered.");
        }

        return $this->providers[$key];
    }

    /**
     * Check if a provider is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * Get all registered providers.
     *
     * @return array<string, OAuthProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
