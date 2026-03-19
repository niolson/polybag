<?php

namespace App\Http\Integrations\Concerns;

use DateInterval;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Connector;

/**
 * Provides cached OAuth2 authentication for Saloon connectors.
 *
 * Connectors using this trait must also use the ClientCredentialsGrant trait
 * and implement the abstract methods to define cache configuration.
 *
 * @phpstan-require-extends Connector
 */
trait HasCachedAuthentication
{
    /**
     * Get the cache key for storing the authenticator.
     */
    abstract protected static function getAuthenticatorCacheKey(): string;

    /**
     * Get an authenticated connector instance with cached credentials.
     */
    public static function getAuthenticatedConnector(): static
    {
        /** @phpstan-ignore new.static */
        $connector = new static;

        $cacheKey = static::getAuthenticatorCacheKey();

        $authenticator = Cache::get($cacheKey);

        if (! $authenticator) {
            $authenticator = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($connector, $cacheKey) {
                $cached = Cache::get($cacheKey);
                if ($cached) {
                    return $cached;
                }

                $authenticator = $connector->getAccessToken();

                Cache::put(
                    $cacheKey,
                    $authenticator,
                    $authenticator->getExpiresAt()->sub(DateInterval::createFromDateString('10 minutes'))
                );

                return $authenticator;
            });
        }

        $connector->authenticate($authenticator);

        return $connector;
    }
}
