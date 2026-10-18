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
    /** Made by Manual Ship so a rate can be shown; not an order until a label is bought. */
    case Draft = 'draft';
    case Open = 'open';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    /**
     * The statuses a person can set or filter by. A draft is Manual Ship's
     * working copy and never listed.
     *
     * @return array<string, string>
     */
    public static function selectable(): array
    {
        return collect(self::cases())
            ->reject(fn (self $status): bool => $status === self::Draft)
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])
            ->all();
    }

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::Shipped => 'Shipped',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Open => 'warning',
            self::Shipped => 'success',
            self::Cancelled => 'danger',
        };
    }
}
