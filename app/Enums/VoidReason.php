<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a label was voided, as far as PolyBag can tell at the moment it happens.
 *
 * One value per way `Package::clearShipping()` is reached: an operator asking
 * for the void through the label workflow, the Shopify fulfillment
 * synchronizer learning after the fact that the label was voided in the
 * Shopify admin, and a manager recording a void the source already made
 * without asking it again. What the carrier said back is a different
 * question, kept off this enum on purpose (package-label-history/04).
 */
enum VoidReason: string implements HasLabel
{
    /** An operator voided the label through PolyBag. */
    case Operator = 'operator';

    /** The postage source reported the label voided outside PolyBag. */
    case VoidedUpstream = 'voided_upstream';

    /**
     * A manager recorded a void without asking the postage source: one it
     * accepted that PolyBag failed to record, or one made on the source's own
     * site (`project-review/10`).
     */
    case Recorded = 'recorded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operator => 'Operator',
            self::VoidedUpstream => 'Reported by postage source',
            self::Recorded => 'Recorded by a manager',
        };
    }
}
