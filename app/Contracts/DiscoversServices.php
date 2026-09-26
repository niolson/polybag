<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\RateResponse;

/**
 * A postage source whose services are discovered per quote rather than
 * authored in the catalog.
 *
 * Amazon Buy Shipping is the only one today. A shipping method asks it
 * through its `amazon` policy row (`carrier-catalog-reset/12`). What it sells
 * is whatever the quote returns, each offer carrying its observed service
 * identity, and only those offers stand for the source: a rate without one
 * would be judged by the method's `direct` row rather than its `amazon` row
 * (`carrier-catalog-reset/13`).
 */
interface DiscoversServices extends PostageOfferSource
{
    /**
     * The `observed_services.source` key this source's offers carry on
     * {@see RateResponse::$observedService}.
     */
    public function observationSource(): string;
}
