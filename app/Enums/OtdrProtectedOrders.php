<?php

namespace App\Enums;

use App\Models\Shipment;
use Filament\Support\Contracts\HasLabel;

/**
 * The kinds of Amazon order a shipping method can require OTDR protection for
 * (`amazon-buy-shipping/17`).
 *
 * Prime and Premium follow {@see AmazonOrderProgram}; everything else Amazon
 * sends — ordinary orders, `FBM_SHIP_PLUS`, and orders imported before
 * programs were recorded — is {@see self::Other}.
 */
enum OtdrProtectedOrders: string implements HasLabel
{
    case Prime = 'prime';
    case Premium = 'premium';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Prime => 'Prime orders',
            self::Premium => 'Premium orders',
            self::Other => 'Other Amazon orders',
        };
    }

    /**
     * Which of these an Amazon order counts as. An order in both Prime and
     * Premium is both.
     *
     * @return list<self>
     */
    public static function forShipment(Shipment $shipment): array
    {
        $kinds = array_map(fn (AmazonOrderProgram $program): self => match ($program) {
            AmazonOrderProgram::Prime => self::Prime,
            AmazonOrderProgram::Premium => self::Premium,
        }, AmazonOrderProgram::forShipment($shipment));

        return $kinds === [] ? [self::Other] : $kinds;
    }
}
