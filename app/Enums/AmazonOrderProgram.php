<?php

namespace App\Enums;

use App\Models\Shipment;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The Amazon order programs PolyBag distinguishes.
 *
 * Amazon lists an order's programs as codes on the order (`programs` in Orders
 * v2026-01-01), which the import stores verbatim as `amazon_programs` in the
 * Shipment's metadata. This is the one place those codes are given a meaning,
 * so shipping rules and badges name a program rather than a code.
 *
 * Prime and Premium are separate programs and a seller can run either without
 * the other. `FBM_SHIP_PLUS` is deliberately not mapped: it is a separate
 * program for shipments from China, not Seller Fulfilled Prime.
 */
enum AmazonOrderProgram: string implements HasColor, HasLabel
{
    case Prime = 'prime';
    case Premium = 'premium';

    public function getLabel(): string
    {
        return match ($this) {
            self::Prime => 'Prime',
            self::Premium => 'Premium',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Prime => 'info',
            self::Premium => 'warning',
        };
    }

    /**
     * The Amazon program codes that mean this program.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return match ($this) {
            self::Prime => ['PRIME'],
            self::Premium => ['PREMIUM'],
        };
    }

    /**
     * The programs a Shipment's imported Amazon order is enrolled in. Empty for
     * a non-Amazon Shipment and for one imported before programs were recorded.
     *
     * @return list<self>
     */
    public static function forShipment(Shipment $shipment): array
    {
        $codes = $shipment->metadata['amazon_programs'] ?? [];

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $program): bool => array_intersect($program->codes(), $codes) !== [],
        ));
    }

    public function appliesTo(Shipment $shipment): bool
    {
        return in_array($this, self::forShipment($shipment), true);
    }
}
