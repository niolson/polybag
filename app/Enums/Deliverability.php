<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum Deliverability: string implements HasColor, HasIcon, HasLabel
{
    case No = 'no';
    case Maybe = 'maybe';
    case Yes = 'yes';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::No => 'No',
            self::Maybe => 'Maybe',
            self::Yes => 'Yes',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::No => 'danger',
            self::Maybe => 'warning',
            self::Yes => 'success',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::No => Heroicon::XCircle,
            self::Maybe => Heroicon::ExclamationTriangle,
            self::Yes => Heroicon::CheckCircle,
        };
    }
}
