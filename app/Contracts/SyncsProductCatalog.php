<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * A source that can list its whole product catalog, for a catalog sync
 * (`product-catalog-sync/02`). Beside {@see DataSourceInterface}: the
 * catalog is read whether or not the connection imports orders.
 */
interface SyncsProductCatalog
{
    /**
     * The catalog's items, a page at a time, each mapped to the item array
     * `ImportReferenceResolver::productIdFor()` takes. Each page is written
     * before the next is read.
     *
     * The source checks what reading its catalog needs, which may be less
     * than an order import does. A page that cannot be read throws, and the
     * run stops there.
     *
     * @return iterable<int, Collection<int, array<string, mixed>>>
     */
    public function fetchProductCatalogPages(): iterable;
}
