<?php

namespace App\Services\ProductCatalog;

use RuntimeException;

/**
 * The run a sync job was writing is no longer the connection's running run:
 * it was interrupted and a new sync replaced it. The job stops quietly.
 */
class ProductCatalogSyncSuperseded extends RuntimeException {}
