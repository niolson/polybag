<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use Illuminate\Support\Collection;

/**
 * A postage source that sells labels it cannot quote.
 *
 * Shopify Shipping is the only one today, and the reason this contract exists
 * rather than a flag on {@see CarrierAdapterInterface}: its Admin API has no
 * rate operation at all, and its `ShippingLabel` reports no service, no rate
 * and no price before or after purchase. Omitting `preferredRateSelection`
 * leaves Shopify an unconstrained choice — the buyer's delivery method, then
 * shop preference, then Shopify's own recommendation — so what is on offer is
 * a purchase, not a rate (ADR-0003 decision 6).
 *
 * Nothing here produces a {@see RateResponse}, so blind purchases never enter
 * price comparison. Automation may buy one only when a shipping rule names it
 * or it is the ShippingMethod's sole configured, package-eligible choice, and
 * the connection's postage setting allows automation. The offers are advertised
 * only by a connection whose postage setting sells (ADR-0006 decision 6).
 */
interface BlindPurchaseSource extends PostageOfferSource
{
    /**
     * What this source will sell for the request's package, priceless.
     *
     * Empty is the normal answer: a package this source cannot buy for, a
     * connection that does not sell postage, or a selection this source does not
     * advertise. It is never an error, and never a reason to warn a packer.
     *
     * @param  array<string>  $serviceCodes  Preferences the caller is willing to ask for
     * @return Collection<int, BlindPurchaseOffer>
     */
    public function blindPurchaseOffers(RateRequest $request, array $serviceCodes): Collection;
}
