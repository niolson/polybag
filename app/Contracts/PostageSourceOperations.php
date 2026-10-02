<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Models\Package;
use App\Services\PostageSources\OfferStore;

/**
 * The operations that belong to whoever *bought* a label.
 *
 * The other half of the split in ADR-0002 decision 7. `packages.carrier` used to
 * answer these by standing in for both the carrier of record and the adapter to
 * call; it no longer can, because a Shopify-bought parcel records USPS as its
 * carrier and asking USPS to void it would reach an account that never bought
 * it.
 *
 * `PostageSourceDispatcher` resolves exactly one implementation per package from
 * the `postage_source` discriminator, and asks it. There is no fallback chain:
 * when the source that bought the label cannot answer, that is the answer.
 * Trying the carrier next would be a request we hold no entitlement to make.
 *
 * Quoting and purchasing are the two operations of this seam not declared here
 * yet. They still dispatch by carrier name through `CarrierRegistry`. The offer
 * store they were waiting on now exists ({@see OfferStore},
 * ADR-0002 decision 4), but nothing issues an offer yet, so declaring them here
 * would still add methods nothing could route to. The Amazon adapter is the
 * first source that will.
 */
interface PostageSourceOperations
{
    /**
     * Void the label this source sold us.
     *
     * A source with no void operation reports the failure rather than throwing —
     * Shopify Shipping has none, and says so in words a packer can act on.
     */
    public function voidLabel(Package $package): CancelResponse;

    /**
     * Ask this source where the parcel is, under the entitlement it holds.
     *
     * Three outcomes, all distinct, and the middle one is the reason this
     * returns a response object rather than a nullable status:
     *
     * - **unsupported** — {@see TrackShipmentResponse::unsupported()}. This
     *   source has no tracking to give at all.
     * - **no answer** — {@see TrackShipmentResponse::failure()}. The source
     *   could be asked but reported nothing about *this* parcel: an unmatched
     *   fulfillment, a status it has not published yet, a throttled reply.
     *   Never recorded as a status; doing so would attribute another parcel's
     *   progress to this one.
     * - **a result** — {@see TrackShipmentResponse::success()}.
     */
    public function trackShipment(Package $package): TrackShipmentResponse;

    /**
     * Whether *this package* may go on a manifest we create.
     *
     * A SCAN form is a claim that we tendered these parcels on our own account,
     * which is false for channel-bought postage whatever carrier is carrying it.
     * Distinct from {@see CarrierPolicy::supportsCarrierManifest()}, which asks
     * whether the carrier runs a manifest program at all.
     */
    public function supportsPackageManifest(Package $package): bool;
}
