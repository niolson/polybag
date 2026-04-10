<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum HazmatClass: string implements HasLabel
{
    case LithiumBatteryInEquipment = 'lithium_battery_in_equipment';
    case LithiumBatteryStandalone = 'lithium_battery_standalone';
    case LithiumBatteryGroundOnly = 'lithium_battery_ground_only';
    case DryIce = 'dry_ice';
    case CrematedRemains = 'cremated_remains';

    public function getLabel(): string
    {
        return match ($this) {
            self::LithiumBatteryInEquipment => 'Lithium Battery (in equipment)',
            self::LithiumBatteryStandalone => 'Lithium Battery (standalone)',
            self::LithiumBatteryGroundOnly => 'Lithium Battery (ground shipping only)',
            self::DryIce => 'Dry Ice',
            self::CrematedRemains => 'Cremated Remains',
        };
    }
}
