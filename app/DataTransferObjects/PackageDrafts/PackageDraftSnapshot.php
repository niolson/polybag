<?php

namespace App\DataTransferObjects\PackageDrafts;

final readonly class PackageDraftSnapshot
{
    /**
     * @param  array<int, PackageDraftItemSnapshot>  $items
     */
    public function __construct(
        public int $packageDraftId,
        public int $shipmentId,
        public Measurements $measurements,
        public ?int $boxSizeId,
        public bool $weightMismatch,
        public bool $readyToShip,
        public array $items,
    ) {}
}
