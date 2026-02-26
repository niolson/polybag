<?php

namespace App\Enums;

use App\Models\Shipment;
use Filament\Support\Contracts\HasLabel;

enum DestinationZone: string implements HasLabel
{
    case ContinentalUs = 'continental_us';
    case NonContinentalUs = 'non_continental_us';
    case UsTerritories = 'us_territories';
    case International = 'international';

    public function getLabel(): string
    {
        return match ($this) {
            self::ContinentalUs => 'Continental US',
            self::NonContinentalUs => 'Non-Continental US',
            self::UsTerritories => 'US Territories',
            self::International => 'International',
        };
    }

    /**
     * Return the state/territory codes that belong to this zone.
     *
     * @return array<string>
     */
    public function states(): array
    {
        return match ($this) {
            self::ContinentalUs => [
                'AL', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA',
                'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA',
                'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM',
                'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD',
                'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
            ],
            self::NonContinentalUs => ['AK', 'HI'],
            self::UsTerritories => ['PR', 'GU', 'VI', 'AS', 'MP'],
            self::International => [],
        };
    }

    /**
     * Check if a shipment's destination matches this zone.
     */
    public static function matchesShipment(self $zone, Shipment $shipment): bool
    {
        $country = $shipment->validated_country ?? $shipment->country;
        $state = $shipment->validated_state_or_province ?? $shipment->state_or_province;

        if ($zone === self::International) {
            return $country !== 'US';
        }

        // All US-based zones require US country
        if ($country !== 'US') {
            return false;
        }

        return in_array(strtoupper($state), $zone->states());
    }
}
