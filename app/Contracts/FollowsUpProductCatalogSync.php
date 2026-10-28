<?php

namespace App\Contracts;

use App\DataTransferObjects\ShipmentImport\ProductCatalogPage;
use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Models\DataSource;

/**
 * A {@see SyncsProductCatalog} source with more to do once its catalog is
 * written (`product-catalog-sync/04`): Amazon looks up barcodes and part
 * numbers in its catalog, as a job of its own after the listings.
 */
interface FollowsUpProductCatalogSync
{
    /**
     * A page of the catalog was written. Called inside the page's
     * transaction, which may be retried, so it must be idempotent.
     */
    public function productCatalogPageWritten(DataSource $dataSource, ProductCatalogPage $page): void;

    /**
     * The run read to the end of the catalog, complete or incomplete. Its
     * status is already recorded and stays as it is, whatever this does.
     */
    public function productCatalogSyncFinished(DataSource $dataSource, ProductCatalogSyncRun $run): void;
}
