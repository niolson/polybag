<?php

namespace App\Enums;

use App\Models\Shipment;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a Shipment's latest pack slip stands. The three never overlap: a slip is
 * never printed, printed and current, or printed and out of date.
 */
enum PackSlipState: string implements HasColor, HasLabel
{
    case Printed = 'printed';
    case NotPrinted = 'not_printed';
    case ChangedSincePrinted = 'changed_since_printed';

    public static function forShipment(Shipment $shipment): self
    {
        return match (true) {
            ! $shipment->hasPrintedPackSlip() => self::NotPrinted,
            $shipment->packSlipIsOutOfDate() => self::ChangedSincePrinted,
            default => self::Printed,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Printed => 'Printed',
            self::NotPrinted => 'Not printed',
            self::ChangedSincePrinted => 'Changed since printed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Printed => 'success',
            self::NotPrinted => 'gray',
            self::ChangedSincePrinted => 'warning',
        };
    }
}
