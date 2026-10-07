<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who pays duties and import charges on an international shipment
 * (ADR-0008 decisions 1 and 2).
 */
enum DutiesTerms: string implements HasLabel
{
    /** Delivered duty paid: the carrier account pays, and the recipient pays nothing at the door. */
    case Ddp = 'ddp';

    /** Delivered duty unpaid: the recipient pays duties and import charges on delivery. */
    case Ddu = 'ddu';

    /**
     * The term an import or form value names, ignoring case and surrounding
     * space, or null when it names none.
     */
    public static function fromInput(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Ddp => 'DDP — duties paid by the shipper',
            self::Ddu => 'DDU — duties paid by the recipient',
        };
    }
}
