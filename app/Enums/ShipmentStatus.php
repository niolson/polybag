<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What happened to the order. Who reported it is `ShipmentStatusOrigin`, kept
 * apart on purpose: an order shipped by other software is shipped, and every
 * filter, report and purge rule treats it so (`order-reconciliation/02`).
 */
enum ShipmentStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Open => 'Open',
            self::Shipped => 'Shipped',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Open => 'warning',
            self::Shipped => 'success',
            self::Cancelled => 'danger',
        };
    }
}
