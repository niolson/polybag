<?php

namespace App\Contracts;

use App\DataTransferObjects\ShipmentImport\ProductCatalogPage;

/**
 * A source that can list its whole product catalog, for a catalog sync
 * (`product-catalog-sync/02`). Beside {@see DataSourceInterface}: the
 * catalog is read whether or not the connection imports orders.
 */
interface SyncsProductCatalog
{
    /**
     * The catalog a page at a time, from the start or from a cursor an
     * earlier page gave. A sync runs as a chain of short jobs, so a read may
     * stop after any page and resume in another job from that page's
     * {@see ProductCatalogPage::$nextCursor}.
     *
     * The source checks what reading its catalog needs, which may be less
     * than an order import does. A page that cannot be read throws, and the
     * run stops there; so does a cursor the source no longer accepts, rather
     * than reading from the start again.
     *
     * @return iterable<int, ProductCatalogPage>
     */
    public function fetchProductCatalogPages(?string $cursor = null): iterable;
}
