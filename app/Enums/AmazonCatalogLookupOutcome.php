<?php

namespace App\Enums;

/**
 * How a slice of the catalog look-up after an Amazon catalog sync ended
 * (`product-catalog-sync/04`).
 */
enum AmazonCatalogLookupOutcome
{
    /** Every recorded SKU was dealt with. */
    case Finished;

    /** The slice's time ran out with SKUs left. */
    case More;

    /**
     * Amazon could not answer (a 5xx, a 429 it kept sending, or no answer at
     * all), so the slice stopped, keeping what it had not done for later.
     */
    case Interrupted;
}
