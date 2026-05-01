<?php

namespace App\DataTransferObjects\PackageDrafts;

final readonly class PackageDraftItemSnapshot
{
    /**
     * @param  array<int, string>  $transparencyCodes
     */
    public function __construct(
        public int $shipmentItemId,
        public int $productId,
        public int $quantity,
        public array $transparencyCodes,
    ) {}
}
