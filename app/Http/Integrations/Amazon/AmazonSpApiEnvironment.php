<?php

namespace App\Http\Integrations\Amazon;

use App\Enums\AppMode;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Services\SettingsService;
use Saloon\Http\Request;

/**
 * Whether an SP-API request goes to the sandbox or the production host: the
 * one answer {@see AmazonSpApiConnector} sends by, and the import asks
 * (`live-store-demo/03`).
 *
 * Outside Demo every request follows `sandbox_mode`. In Demo, which forces
 * `sandbox_mode` on, a request that {@see ReadsSellerData} goes to production,
 * so a prospect's real orders, catalog and marketplaces are read, while every
 * {@see PostageRequest} stays on the sandbox. A request that declares neither,
 * such as one someone forgot to mark, stays on the sandbox too.
 *
 * {@see readsFromSandbox()} is the same answer for the order search, so the
 * import can never treat production orders as Amazon's canned test cases, or
 * the reverse, while the connector sends the search somewhere else.
 */
class AmazonSpApiEnvironment
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Whether this request, or a request of this class, goes to the sandbox.
     *
     * @param  Request|class-string<Request>  $request
     */
    public function usesSandbox(Request|string $request): bool
    {
        if (! $this->settings->isSandboxMode()) {
            return false;
        }

        return AppMode::current() !== AppMode::Demo
            || ! is_a($request, ReadsSellerData::class, true)
            || is_a($request, PostageRequest::class, true);
    }

    /**
     * Whether the seller's orders are read from the sandbox, where the search
     * returns Amazon's static test cases rather than the seller's orders.
     */
    public function readsFromSandbox(): bool
    {
        return $this->usesSandbox(SearchOrders::class);
    }
}
