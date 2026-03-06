<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum PackageStatus: string implements HasColor, HasLabel
{
    case Unshipped = 'unshipped';
    case Shipped = 'shipped';
    case Void = 'void';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Unshipped => 'Unshipped',
            self::Shipped => 'Shipped',
            self::Void => 'Void',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Unshipped => 'warning',
            self::Shipped => 'success',
            self::Void => 'danger',
        };
    }
}
