<?php

namespace App\Http\Integrations\Amazon;

use App\Enums\AppMode;

/**
 * Implemented by an SP-API request that only reads the seller's own orders,
 * catalog or account (`live-store-demo/03`).
 *
 * In Demo such a request goes to the production host, so a prospect's real
 * orders import while postage stays on the sandbox
 * ({@see AmazonSpApiEnvironment}). Outside {@see AppMode::Demo} it follows
 * `sandbox_mode` like every other request.
 *
 * Only a request that reads and changes nothing may carry this. A request
 * that buys, voids or reads postage is a {@see PostageRequest}, and one that
 * writes to the seller's orders (`confirmShipment`) declares neither, which
 * keeps it on the sandbox in Demo.
 */
interface ReadsSellerData {}
