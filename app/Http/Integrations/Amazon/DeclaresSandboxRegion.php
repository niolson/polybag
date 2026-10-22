<?php

namespace App\Http\Integrations\Amazon;

use App\Enums\AmazonSpApiRegion;
use App\Services\ShipmentImport\Sources\AmazonSource;

/**
 * Implemented by a request whose sandbox test cases live in a region other than the
 * connector's default.
 *
 * Amazon's sandbox is region-scoped per API, so one host cannot serve every request:
 *
 * - **Orders v2026-01-01** has no NA test case we can drive. The only working one is
 *   Amazon's JP marketplace (`A1VC38T7YXB528`, see
 *   {@see AmazonSource::fetchShipments()}), which
 *   resolves only against the FE host — the NA host 403s with "The marketplaces you
 *   provided are not valid for region"
 *   (https://github.com/amzn/selling-partner-api-models/issues/5126).
 * - **Shipping v2** has NA test cases, so the FE host is wrong for it.
 *
 * Production is unaffected: it is North America for both, and this contract is only
 * consulted for a request going to the sandbox ({@see AmazonSpApiEnvironment}). In Demo
 * an order search goes to production, so its FE region is not used there.
 */
interface DeclaresSandboxRegion
{
    public function sandboxRegion(): AmazonSpApiRegion;
}
