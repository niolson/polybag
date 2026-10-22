<?php

namespace App\Services;

use App\Enums\AppMode;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\DataSource;
use Illuminate\Support\Facades\Cache;

/**
 * Whether PolyBag may write to a connection's store in the current mode
 * (`live-store-demo`, PRD decisions 1 and 2).
 *
 * In Demo, a live Shopify or Amazon store is read and never written to. A
 * Shopify development store is not live, so it gets every write production
 * would send it: a Shopify Shipping label (`02`), a fulfillment and a
 * fulfillment cancel (`01`). Amazon has no development store, so nothing is
 * written to an Amazon store in Demo. A Database connection is not a store,
 * and its write-back runs in every mode. In Production and Dev every write is
 * allowed and no store is asked anything.
 *
 * Kept apart from the paths that write, so each of them asks this one
 * question rather than a copy of it.
 */
class StoreWriteGuard
{
    /**
     * The plan says whether the store is a development store. It is read live,
     * not stored on the connection, so a store that changes plan is never
     * judged on a stale answer for longer than this.
     */
    public const CACHE_MINUTES = 5;

    /**
     * How long an unreadable plan stays unread. Shorter than an answer, so a
     * Shopify blip does not keep a development store live for long, and
     * longer than nothing, so an outage does not add a slow request to every
     * rating.
     */
    public const FAILURE_CACHE_MINUTES = 1;

    /**
     * `partnerDevelopment` is the field to trust. On a store created from the
     * Dev Dashboard, `publicDisplayName` names the plan the store previews,
     * "Plus" or "Basic", not "Development" (`live-store-demo/02`, 2026-10-22).
     * A Partner organization's development store has reported "Development"
     * there, so that counts too.
     */
    private const PLAN_QUERY = '{ shop { plan { partnerDevelopment publicDisplayName } } }';

    private const DEVELOPMENT_PLAN_NAME = 'Development';

    /**
     * Whether PolyBag may write to this connection's store in the current mode.
     */
    public function allowsWritesTo(DataSource $connection): bool
    {
        if (AppMode::current() !== AppMode::Demo) {
            return true;
        }

        return match (true) {
            $connection->isShopify() => $this->isDevelopmentStore($connection),
            $connection->isAmazon() => false,
            default => true,
        };
    }

    /**
     * Whether this Shopify connection's store is a development store, read
     * from its plan and cached briefly. A plan that cannot be read counts as
     * live.
     *
     * Asks Shopify whatever the mode, so a caller outside Demo asks
     * {@see allowsWritesTo()} instead.
     */
    public function isDevelopmentStore(DataSource $connection): bool
    {
        if (! $connection->isShopify()) {
            return false;
        }

        $key = $this->cacheKey($connection);
        $cached = Cache::get($key);

        if (is_bool($cached)) {
            return $cached;
        }

        $isDevelopment = $this->readPlan($connection);

        Cache::put(
            $key,
            $isDevelopment ?? false,
            now()->addMinutes($isDevelopment === null ? self::FAILURE_CACHE_MINUTES : self::CACHE_MINUTES),
        );

        return $isDevelopment ?? false;
    }

    /**
     * True or false from the plan, or null when Shopify did not say.
     */
    private function readPlan(DataSource $connection): ?bool
    {
        try {
            $json = ShopifyConnector::fromSettings(
                array_merge($connection->settings ?? [], $connection->secret_settings ?? [])
            )->send(new GraphQL(self::PLAN_QUERY))->json();
        } catch (\Exception $e) {
            logger()->warning('Could not read a Shopify store\'s plan; treating it as live', [
                'data_source_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $plan = $json['data']['shop']['plan'] ?? null;

        if (! is_array($plan)) {
            logger()->warning('Shopify returned no plan for a store; treating it as live', [
                'data_source_id' => $connection->id,
                'errors' => $json['errors'] ?? null,
            ]);

            return null;
        }

        return ($plan['partnerDevelopment'] ?? null) === true
            || ($plan['publicDisplayName'] ?? null) === self::DEVELOPMENT_PLAN_NAME;
    }

    /**
     * Keyed by the shop domain too, so a connection pointed at another store
     * is asked again rather than judged on the last store's plan.
     */
    private function cacheKey(DataSource $connection): string
    {
        return 'shopify_development_store:'.$connection->id.':'.md5((string) ($connection->settings['shop_domain'] ?? ''));
    }
}
