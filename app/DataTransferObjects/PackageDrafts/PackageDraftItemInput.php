<?php

namespace App\DataTransferObjects\PackageDrafts;

final readonly class PackageDraftItemInput
{
    /**
     * @param  array<int, string>  $transparencyCodes
     * @param  float|null  $value  The unit value the line declares, in the Shipment's currency, over its Shipment item's
     */
    public function __construct(
        public ?int $shipmentItemId,
        public int $productId,
        public int $quantity,
        public array $transparencyCodes = [],
        public ?float $value = null,
    ) {}
}
