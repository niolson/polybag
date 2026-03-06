<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum ShipmentStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Shipped = 'shipped';
    case Void = 'void';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Open => 'Open',
            self::Shipped => 'Shipped',
            self::Void => 'Void',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Open => 'warning',
            self::Shipped => 'success',
            self::Void => 'danger',
        };
    }
}
