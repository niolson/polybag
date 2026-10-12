<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who moved a Shipment to its current status, beside the status itself in the
 * way `VoidReason` sits beside a voided Label (`order-reconciliation/02`).
 *
 * Null on a Shipment nobody has moved off `open`.
 */
enum ShipmentStatusOrigin: string implements HasLabel
{
    /** A Label bought here shipped it. */
    case PolyBag = 'polybag';

    /** The originating connection reported it shipped or cancelled. */
    case Source = 'source';

    /** A manager set it by hand. */
    case Manager = 'manager';

    public function getLabel(): string
    {
        return match ($this) {
            self::PolyBag => 'PolyBag',
            self::Source => 'Reported by the source',
            self::Manager => 'Set by a manager',
        };
    }
}
