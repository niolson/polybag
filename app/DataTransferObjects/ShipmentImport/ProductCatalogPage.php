<?php

namespace App\DataTransferObjects\ShipmentImport;

use Illuminate\Support\Collection;

/**
 * One page of a source's product catalog (`product-catalog-sync/02`): its
 * items, as the item arrays `ImportReferenceResolver::productIdFor()` takes,
 * and where the next page starts, or null after the last.
 */
final readonly class ProductCatalogPage
{
    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    public function __construct(
        public Collection $items,
        public ?string $nextCursor,
    ) {}
}
