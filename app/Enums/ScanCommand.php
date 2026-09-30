<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Built-in commands scanned from a command sheet. Each means the same thing on
 * every page; a page where one does not apply rejects it (ADR-0007, decision 4).
 */
enum ScanCommand: string implements HasDescription, HasLabel
{
    case Ship = 'SHIP';
    case ReprintLast = 'REPRINTLAST';
    case VoidLast = 'VOIDLAST';
    case ZeroScale = 'ZEROSCALE';
    case ClearShipment = 'CLEARSHIPMENT';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ship => 'Buy & Print Label',
            self::ReprintLast => 'Reprint Last Label',
            self::VoidLast => 'Void Last Label',
            self::ZeroScale => 'Zero Scale',
            self::ClearShipment => 'Clear Shipment',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Ship => 'Buy and print a label for the package being packed (same as F12)',
            self::ReprintLast => 'Reprint the label this browser session last bought',
            self::VoidLast => 'Void the label this browser session last bought, if you bought it',
            self::ZeroScale => 'Re-zero the scale with its platform empty',
            self::ClearShipment => 'Clear the loaded shipment and start fresh',
        };
    }
}
