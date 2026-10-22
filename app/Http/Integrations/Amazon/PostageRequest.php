<?php

namespace App\Http\Integrations\Amazon;

/**
 * Implemented by every Shipping v2 request: quoting, buying, voiding,
 * reprinting and tracking postage (`live-store-demo/03`).
 *
 * A postage request follows `sandbox_mode` in every mode, so in Demo, which
 * forces sandbox on, it never reaches the production host. That holds
 * whatever host the order itself was read from. A request that is marked
 * both a read and postage counts as postage ({@see AmazonSpApiEnvironment}).
 */
interface PostageRequest {}
