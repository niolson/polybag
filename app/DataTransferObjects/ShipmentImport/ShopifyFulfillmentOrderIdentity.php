<?php

namespace App\DataTransferObjects\ShipmentImport;

use App\Services\ShopifyGoodsFingerprint;

/**
 * What identifies a Shopify fulfillment order as a piece of shipping work,
 * separately from the GID Shopify currently gives it.
 *
 * Shopify replaces a fulfillment order outright when a label bought against it
 * is voided, and exposes no edge saying which one replaced which, so a
 * replacement can only be recognised by what it describes: the same order, at
 * the same location, for the same goods.
 */
final readonly class ShopifyFulfillmentOrderIdentity
{
    /**
     * @param  string|null  $goodsFingerprint  {@see ShopifyGoodsFingerprint}; null when the goods could not be read
     */
    public function __construct(
        public string $fulfillmentOrderId,
        public ?string $orderId,
        public ?string $locationId,
        public ?string $goodsFingerprint,
    ) {}
}
