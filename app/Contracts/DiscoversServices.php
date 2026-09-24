<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\RateResponse;

/**
 * A postage source whose services are discovered per quote rather than
 * authored in the catalog.
 *
 * Amazon Buy Shipping is the only one today. Its seeded `CarrierService` row
 * is not a service but how a shipping method or rule says "ask Amazon", so a
 * rule naming that row names the source, not anything it will sell. What it
 * sells is whatever the quote returns, each offer carrying its observed
 * service identity, and only those offers stand for the source: a rate built
 * from the catalog row would look like authored configuration and skip the
 * approval check (`amazon-buy-shipping/19`).
 */
interface DiscoversServices extends PostageOfferSource
{
    /**
     * The `observed_services.source` key this source's offers carry on
     * {@see RateResponse::$observedService}.
     */
    public function observationSource(): string;
}
