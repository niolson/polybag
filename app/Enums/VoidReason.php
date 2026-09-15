<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a label was voided, as far as PolyBag can tell at the moment it happens.
 *
 * Two values because there are two callers of `Package::clearShipping()`: an
 * operator asking for the void through the label workflow, and the Shopify
 * fulfillment synchronizer learning after the fact that the label was voided in
 * the Shopify admin. What the carrier said back is a different question, kept
 * off this enum on purpose (package-label-history/04).
 */
enum VoidReason: string implements HasLabel
{
    /** An operator voided the label through PolyBag. */
    case Operator = 'operator';

    /** The postage source reported the label voided outside PolyBag. */
    case VoidedUpstream = 'voided_upstream';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operator => 'Operator',
            self::VoidedUpstream => 'Reported by postage source',
        };
    }
}
