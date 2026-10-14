<?php

namespace App\DataTransferObjects\Shipping;

/**
 * What one batched read of Shopify fulfillment orders learned: every
 * fulfillment of each order it could read in full, and why it could not read
 * the rest.
 *
 * An ID in neither list is one Shopify returned nothing for — deleted, or not a
 * fulfillment order — which is no answer, the same as a fulfillment order
 * carrying no fulfillment for the package asked about.
 */
readonly class ShopifyFulfillmentLookup
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $fulfillments  keyed by fulfillment order ID
     * @param  array<string, string>  $failures  fulfillment order ID => why its later fulfillments could not be read
     */
    public function __construct(
        public array $fulfillments,
        public array $failures = [],
    ) {}
}
