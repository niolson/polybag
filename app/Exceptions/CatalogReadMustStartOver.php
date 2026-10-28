<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A product catalog read that cannot carry on from where it stopped
 * (`product-catalog-sync/02`): the source no longer accepts the saved cursor,
 * or the connection cannot read the catalog at all. The next sync starts from
 * the first page rather than resuming.
 */
class CatalogReadMustStartOver extends RuntimeException {}
