<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The validators that can answer for a Shipment's address. Recorded on each
 * answer, and on the Shipment as `validation_source` for the one that settled
 * its current result.
 */
enum AddressValidator: string implements HasLabel
{
    case Usps = 'usps';
    case Google = 'google';
    case Fedex = 'fedex';
    case Ups = 'ups';
    case Fake = 'fake';

    public function getLabel(): string
    {
        return match ($this) {
            self::Usps => 'USPS',
            self::Google => 'Google',
            self::Fedex => 'FedEx',
            self::Ups => 'UPS',
            self::Fake => 'Fake',
        };
    }

    /**
     * Whether each request costs money. Google is billed per request beyond a
     * monthly free allowance, which is counted as paid; FedEx and UPS are free
     * to their account holders.
     */
    public function isPaid(): bool
    {
        return match ($this) {
            self::Usps, self::Google => true,
            self::Fedex, self::Ups, self::Fake => false,
        };
    }
}
