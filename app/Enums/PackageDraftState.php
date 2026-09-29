<?php

namespace App\Enums;

use App\Models\Package;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How far an unshipped Package Draft has got. Computed, never stored: see
 * {@see Package::draftStateSql()}.
 */
enum PackageDraftState: string implements HasColor, HasLabel
{
    /** No box, no measurement, nothing packed. */
    case Empty = 'empty';

    /** Started, but the purchase would refuse it. */
    case Packing = 'packing';

    /** Passes the purchase readiness rule. */
    case Ready = 'ready';

    public function getLabel(): string
    {
        return match ($this) {
            self::Empty => 'Empty draft',
            self::Packing => 'Packing',
            self::Ready => 'Ready to ship',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Empty => 'gray',
            self::Packing => 'warning',
            self::Ready => 'info',
        };
    }
}
