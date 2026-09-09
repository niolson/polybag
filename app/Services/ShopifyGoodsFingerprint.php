<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentItem;

/**
 * A comparable fingerprint of the goods a Shopify fulfillment order is for.
 *
 * Shopify replaces a fulfillment order outright rather than reopening it, and
 * publishes no edge saying which one replaced which, so a replacement can only
 * be recognised by what it describes. Order and assigned location get most of
 * the way there and stop exactly where it matters: one order can hold several
 * fulfillment orders at one location, split by `fulfillmentOrderSplit`, and
 * those differ only in their line items. Without this a sibling that closed for
 * its own reasons reads as a replacement, and the shipment is re-pointed at
 * another parcel's work — which then buys a label for the wrong goods.
 *
 * Recorded on the shipment at import time rather than derived from
 * `shipment_items`, because item import is a per-source setting: a source with
 * it switched off has no items to compare and would otherwise have no evidence
 * at all. Items remain the fallback for shipments imported before the
 * fingerprint existed.
 */
class ShopifyGoodsFingerprint
{
    /** Where the fingerprint lives on a shipment. */
    public const METADATA_KEY = 'shopify_goods_fingerprint';

    /**
     * Fingerprint a set of line items, in either the shape Shopify returns or
     * the shape PolyBag maps them to.
     *
     * Null for nothing to fingerprint. That is not "no goods" but "no
     * evidence", and every caller treats it as a reason not to match rather
     * than as something that matches another emptiness.
     *
     * @param  iterable<int, array<string, mixed>>  $lineItems
     */
    public function forLineItems(iterable $lineItems, string $quantityKey = 'quantity'): ?string
    {
        /** @var array<string, int> $quantities */
        $quantities = [];

        foreach ($lineItems as $lineItem) {
            $sku = self::skuFor($lineItem);
            $quantity = (int) ($lineItem[$quantityKey] ?? 0);

            if ($sku === null || $quantity <= 0 || ($lineItem['requiresShipping'] ?? true) === false) {
                continue;
            }

            $quantities[$sku] = ($quantities[$sku] ?? 0) + $quantity;
        }

        if ($quantities === []) {
            return null;
        }

        ksort($quantities);

        return hash('sha256', (string) json_encode($quantities));
    }

    /**
     * The fingerprint a shipment carries, or the one its items imply.
     */
    public function forShipment(Shipment $shipment): ?string
    {
        $stored = $shipment->metadata[self::METADATA_KEY] ?? null;

        if (filled($stored)) {
            return (string) $stored;
        }

        $shipment->loadMissing('shipmentItems.product');

        return $this->forLineItems($shipment->shipmentItems->map(fn (ShipmentItem $item): array => [
            'sku' => $item->product->sku,
            'quantity' => $item->quantity,
        ]));
    }

    /**
     * The SKU a Shopify line item is identified by.
     *
     * A variant with no SKU of its own gets a stable one derived from its
     * variant ID — the same substitute `ShopifySource` records on the shipment
     * item, so that a fingerprint taken from a raw Shopify node and one taken
     * from an imported shipment describe the same goods with the same strings.
     *
     * @param  array<string, mixed>  $lineItem
     */
    public static function skuFor(array $lineItem): ?string
    {
        $sku = $lineItem['sku'] ?? null;

        if (filled($sku)) {
            return (string) $sku;
        }

        $variantId = $lineItem['variant']['id'] ?? null;

        return filled($variantId)
            ? 'SHOPIFY-V-'.preg_replace('/.*\//', '', (string) $variantId)
            : null;
    }
}
