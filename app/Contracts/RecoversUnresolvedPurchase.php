<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Services\PostageSources\OfferStore;

/**
 * A source that can be asked what happened to a purchase we never heard back
 * about.
 *
 * The fifth property ADR-0002 decision 4 asks of the offer store, and the half
 * {@see OfferStore} cannot supply on its own: it can make "spent, nothing
 * confirmed" a visible state and refuse to spend anything else on the package,
 * but only the source knows whether a label exists.
 *
 * Without this the state is terminal for the parcel — a single dropped
 * connection leaves a package nobody can ship until a human checks the seller's
 * account and edits the row. With it, the next attempt asks.
 *
 * Not every source can answer. Implementing this is a claim that the source
 * can be asked about a purchase *without buying anything*: a side-effect-free
 * way to ask, keyed by something we chose and sent with the purchase, so the
 * question can be put even when the reply never arrived. An idempotent
 * purchase is one such way — Amazon's `x-amzn-IdempotencyKey` makes the
 * repeated request a lookup — but not the only one: USPS's label endpoint is
 * not idempotent, yet its reprint by `X-Idempotency-Key` is a separate call
 * that buys nothing, and UPS's Label Recovery by a `ReferenceNumber` is the
 * same shape. A source with no such call must not implement this contract,
 * because the question and the second purchase would be the same request.
 */
interface RecoversUnresolvedPurchase
{
    /**
     * Ask the source about the purchase {@see ShipRequest::$offer} was spent on.
     *
     * Three answers, and conflating any two of them costs either money or a
     * usable package:
     *
     * - **a successful {@see ShipResponse}** — the label exists. It is the
     *   purchase that was already paid for, not a new one, and the package is
     *   shipped on it.
     * - **a failed `ShipResponse`** — the source is *certain* nothing was
     *   bought. The offer resolves as declined and the package is free to be
     *   quoted again.
     * - **null** — still unknown. The source could not be reached, or answered
     *   something that does not settle the question. The package stays blocked,
     *   which is the safe end of the trade.
     */
    public function recoverPurchase(ShipRequest $request): ?ShipResponse;
}
