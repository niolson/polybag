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
    case ReprintLast = 'REPRINT';
    case VoidLast = 'VOID';
    case ZeroScale = 'ZERO';
    case ClearShipment = 'CLEAR';
    case Ok = 'OK';
    /** Demo and dev installs only: stands in for the product barcodes a demo has no labels for. */
    case NextItem = 'NEXT';

    /**
     * The commands this install accepts. Pack Next Item skips verifying what
     * goes in the box, so a production install never knows it.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $command): bool => $command->isAvailable()));
    }

    public function isAvailable(): bool
    {
        return match ($this) {
            self::NextItem => AppMode::current() !== AppMode::Production,
            default => true,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Ship => 'Ship',
            self::ReprintLast => 'Reprint Last Label',
            self::VoidLast => 'Void Last Label',
            self::ZeroScale => 'Zero Scale',
            self::ClearShipment => 'Clear Shipment',
            self::Ok => 'OK',
            self::NextItem => 'Pack Next Item (Demo)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Ship => 'Ship the packed package (same as F12)',
            self::ReprintLast => 'Reprint the label this browser session last bought',
            self::VoidLast => 'Void the label this browser session last bought',
            self::ZeroScale => 'Zero the scale',
            self::ClearShipment => 'Clear the loaded shipment and start fresh',
            self::Ok => 'Acknowledge the alert on screen (same as Enter)',
            self::NextItem => 'Demo installs only: pack the next unpacked item as if its barcode were scanned',
        };
    }
}
