<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Why a Shipment needs a person to look at it: its source reported something
 * PolyBag should not settle on its own (`order-reconciliation/02`). A manager
 * clears the flag once it is dealt with.
 */
enum ShipmentReviewReason: string implements HasColor, HasDescription, HasLabel
{
    /** The source shipped some of the order's items from somewhere else. */
    case PartiallyFulfilledElsewhere = 'partially_fulfilled_elsewhere';

    /**
     * The source cancelled an order PolyBag already shipped. Its Label may need
     * voiding and the parcel stopping; this is the case that costs money.
     */
    case CancelledAfterShipped = 'cancelled_after_shipped';

    /** The source closed an order that has a Package Draft to unpack. */
    case ClosedWithDraft = 'closed_with_draft';

    public function getLabel(): string
    {
        return match ($this) {
            self::PartiallyFulfilledElsewhere => 'Partly fulfilled elsewhere',
            self::CancelledAfterShipped => 'Cancelled after shipping',
            self::ClosedWithDraft => 'Closed with a draft',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PartiallyFulfilledElsewhere => 'The source reports some of these items shipped from somewhere else. Check what is left to send.',
            self::CancelledAfterShipped => 'The source cancelled this order after PolyBag shipped it. Void the label or stop the parcel if you still can.',
            self::ClosedWithDraft => 'The source closed this order while a package was being prepared. Unpack it and return the items to stock.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CancelledAfterShipped => 'danger',
            default => 'warning',
        };
    }
}
