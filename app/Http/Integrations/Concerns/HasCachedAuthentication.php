<?php

namespace App\Http\Integrations\Concerns;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Saloon\Contracts\OAuthAuthenticator;
use Saloon\Http\Auth\AccessTokenAuthenticator;
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

        $cached = Cache::get($cacheKey);

        if (! is_array($cached)) {
            $cached = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($connector, $cacheKey) {
                $recached = Cache::get($cacheKey);
                if (is_array($recached)) {
                    return $recached;
                }

                $authenticator = $connector->getAccessToken();
                $data = static::serializeAuthenticator($authenticator);

                Cache::put(
                    $cacheKey,
                    $data,
                    $authenticator->getExpiresAt()->sub(DateInterval::createFromDateString('10 minutes'))
                );

                return $data;
            });
        }

        $connector->authenticate(static::deserializeAuthenticator($cached));

        return $connector;
    }

    public static function serializeAuthenticator(OAuthAuthenticator $authenticator): array
    {
        return [
            'access_token' => $authenticator->getAccessToken(),
            'refresh_token' => $authenticator->getRefreshToken(),
            'expires_at' => $authenticator->getExpiresAt()?->getTimestamp(),
        ];
    }

    public static function deserializeAuthenticator(array $data): AccessTokenAuthenticator
    {
        return new AccessTokenAuthenticator(
            $data['access_token'],
            $data['refresh_token'] ?? null,
            isset($data['expires_at']) ? new DateTimeImmutable('@'.$data['expires_at']) : null,
        );
    }
}
