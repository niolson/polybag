<?php

namespace App\DataTransferObjects\PackageDrafts;

final readonly class PackageDraftInput
{
    /**
     * @param  array<int, PackageDraftItemInput>  $items
     */
    public function __construct(
        public Measurements $measurements,
        public ?int $boxSizeId = null,
        public array $items = [],
    ) {}
}
