<?php

namespace App\DataTransferObjects\ShipmentImport;

/**
 * One parcel a source says shipped an order: a Shopify fulfillment's tracking
 * entry, or an Amazon shipment confirmation. A parcel with no tracking number
 * is someone else's, since PolyBag's exports always send one.
 */
final readonly class SourceParcel
{
    public function __construct(
        public ?string $carrier,
        public ?string $trackingNumber,
    ) {}
}
