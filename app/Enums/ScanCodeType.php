<?php

namespace App\Enums;

/**
 * The registry of type tokens in a PolyBag code (ADR-0007). Tokens must stay
 * prefix-free: no token may be the start of another, so a code parses one way.
 */
enum ScanCodeType: string
{
    case Shipment = 'S';
    case Package = 'P';
    case BoxSize = 'B';
    case Command = 'C';
    /** Reserved: an operator-defined action, by the ID of its record. */
    case Action = 'M';

    /**
     * The pattern a code's body must match after this token.
     */
    public function bodyPattern(): string
    {
        return match ($this) {
            self::Command => '/^[A-Z]{1,32}$/',
            self::Shipment, self::Package, self::BoxSize, self::Action => '/^\d{1,18}$/',
        };
    }
}
