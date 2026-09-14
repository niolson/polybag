<?php

namespace App\Enums;

/**
 * Why a label was voided, as far as PolyBag can tell at the moment it happens.
 *
 * Two values because there are two callers of `Package::clearShipping()`: an
 * operator asking for the void through the label workflow, and the Shopify
 * fulfillment synchronizer learning after the fact that the label was voided in
 * the Shopify admin. What the carrier said back is a different question, kept
 * off this enum on purpose (package-label-history/04).
 */
enum VoidReason: string
{
    /** An operator voided the label through PolyBag. */
    case Operator = 'operator';

    /** The postage source reported the label voided outside PolyBag. */
    case VoidedUpstream = 'voided_upstream';
}
