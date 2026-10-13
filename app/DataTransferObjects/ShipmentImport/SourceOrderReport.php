<?php

namespace App\DataTransferObjects\ShipmentImport;

use App\Enums\ShipmentStatus;

/**
 * What a source reported about an order PolyBag still has open, read into the
 * two closures `order-reconciliation` decision 3 knows: cancelled, or shipped
 * with the parcels the source lists. A source that reports anything else has
 * no report to make, and the Shipment stays as it is.
 */
final readonly class SourceOrderReport
{
    /**
     * @param  string  $description  what the source said, for the audit row
     * @param  list<SourceParcel>  $parcels
     */
    private function __construct(
        public ShipmentStatus $status,
        public string $description,
        public array $parcels = [],
    ) {}

    /**
     * The order was cancelled, or withdrawn from the work this connection
     * imports: closed with nothing shipped, or moved to a location it ignores.
     */
    public static function cancelled(string $description): self
    {
        return new self(ShipmentStatus::Cancelled, $description);
    }

    /**
     * @param  list<SourceParcel>  $parcels
     */
    public static function shipped(string $description, array $parcels): self
    {
        return new self(ShipmentStatus::Shipped, $description, $parcels);
    }
}
