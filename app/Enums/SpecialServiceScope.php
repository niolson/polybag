<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SpecialServiceScope: string implements HasLabel
{
    case Shipment = 'shipment';
    case Package = 'package';

    public function getLabel(): string
    {
        return match ($this) {
            self::Shipment => 'Shipment',
            self::Package => 'Package',
        };
    }
}
