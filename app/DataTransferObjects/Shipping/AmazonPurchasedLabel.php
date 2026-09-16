<?php

namespace App\DataTransferObjects\Shipping;

use App\Services\AmazonBuyShippingService;

/**
 * What `purchaseShipment` hands back.
 *
 * Notably not a carrier, a service or a price: `PurchaseShipmentResult` carries
 * a shipment ID, documents and a promise, and nothing else. Those three come
 * off the offer that was spent, which is the right place for them anyway —
 * the offer is the purchase authority and the rate is display data.
 *
 * @see AmazonBuyShippingService
 */
readonly class AmazonPurchasedLabel
{
    /**
     * @param  string  $shipmentId  Amazon's identifier for the shipment — the only thing that can cancel it, and what tells the channel export the order is already confirmed.
     */
    public function __construct(
        public string $shipmentId,
        public ?string $trackingId = null,
        public ?string $labelData = null,
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        // The customs declaration, where the offering declared one and Amazon
        // returned it — a separate `CUSTOM_FORM` package document, never fused
        // into the label (per Amazon support, 2026-09-16). Its format travels
        // with it because Amazon reports one per document and the print path
        // cannot assume PDF the way it can for UPS and Shopify.
        public ?string $customsFormData = null,
        public ?string $customsFormFormat = null,
    ) {}
}
