<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Models\Package;
use Illuminate\Support\Collection;

/**
 * A postage source that can say what a label will cost before buying it.
 *
 * Reduced twice. ADR-0002 decision 7 moved voiding, tracking and manifest
 * eligibility to {@see PostageSourceOperations} and what a carrier is and will
 * carry to {@see CarrierPolicy}; ADR-0003 decision 6 moved everything a source
 * has to answer whether or not it quotes down into {@see PostageOfferSource},
 * leaving this as the quoting half alone.
 *
 * What remains is the ability to return a {@see RateResponse} — a carrier, a
 * service and a price that were actually offered. A source that cannot state
 * those implements {@see BlindPurchaseSource} instead and never fabricates
 * them.
 */
interface CarrierAdapterInterface extends PostageOfferSource
{
    /**
     * Get shipping rates for the given request (synchronous).
     *
     * The only quoting method every rate source has. One with a rate API
     * implements {@see AsyncRateQuoting} as well and is normally quoted through
     * that instead, off the packer's critical path.
     *
     * @param  array<string>  $serviceCodes  Filter to these service codes only
     * @return Collection<int, RateResponse>
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection;

    /**
     * Resolve a rule-pre-selected rate into a fully-qualified rate with metadata.
     *
     * For carriers like USPS where one service code maps to many rate variants
     * (cubic tiers, single-piece, etc.), this fetches rates and picks the cheapest
     * matching variant. Other carriers return the rate as-is.
     *
     * Either way the answer passes through the shared packaging filter first
     * (ADR-0005 decision 4, the pre-selection site): a rule names a service,
     * never a packaging, so the rate it hands over is the shipper's own
     * packaging and a Package in carrier packaging cannot use it. Null means
     * no compatible variant of this service exists for this Package. The
     * caller treats that as "no pre-selection" and falls through to rate
     * shopping, where the same filter runs on real rates.
     */
    public function resolvePreSelectedRate(RateResponse $rate, Package $package): ?RateResponse;

    /**
     * Which carrier packaging this rate is valid in, classified by the adapter
     * that produced it.
     *
     * ADR-0005 decision 3: the adapter that produced the rate sets the
     * requirement, in its own vocabulary, because it is the only party that
     * knows. It classifies from the same fields it will send to the carrier —
     * USPS from `mailClass` and `rateIndicator` in the metadata, FedEx from
     * `isOneRate` and the packaging it named, Amazon from the serviceId — so
     * the requirement stamped on the rate at quote time and the one the
     * purchase re-checks come from one function and cannot disagree.
     *
     * The purchase path asks this rather than reading
     * {@see RateResponse::$packagingRequirement}: for a direct-carrier rate
     * that field is rebuilt from browser state, and a check that trusted it
     * would be a check the browser could switch off. The stamped field is for
     * display and the rate-shopping filter only.
     *
     * The metadata is browser state too, so this is consistency rather than
     * authority: the classifier must read exactly the fields the ship body
     * sends and nothing else, so that no metadata can classify as the
     * shipper's packaging while buying the carrier's. An indicator or code the
     * classifier does not recognise must not fall through to
     * `shipperPackaging()` — it throws instead, and the purchase path turns
     * that into a refusal. Authority — the quoted rate restored server-side
     * behind an opaque identifier, as an offer already is — is
     * `postage-source-split/14`.
     *
     * @throws UnclassifiablePackagingException when the metadata names a product the adapter cannot place in any packaging
     */
    public function packagingRequirementFor(RateResponse $rate): PackagingRequirement;
}
