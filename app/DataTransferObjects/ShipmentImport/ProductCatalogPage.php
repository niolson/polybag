<?php

namespace App\DataTransferObjects\ShipmentImport;

use Illuminate\Support\Collection;

/**
 * One page of a source's product catalog (`product-catalog-sync/02`): its
 * items, as the item arrays `ImportReferenceResolver::productIdFor()` takes,
 * and where the next page starts, or null after the last.
 *
 * A page may also name parts of the catalog the read had to pass over
 * (`product-catalog-sync/04`: an Amazon date window it could not split), so
 * the run ends incomplete rather than complete.
 */
final readonly class ProductCatalogPage
{
    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  list<string>  $unreadParts  what the read passed over before this page, each named for the run status
     * @param  ?string  $unreadReason  why the source could not read them, in its own words
     */
    public function __construct(
        public Collection $items,
        public ?string $nextCursor,
        public array $unreadParts = [],
        public ?string $unreadReason = null,
    ) {}
}
