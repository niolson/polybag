<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum PickingStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Batched = 'batched';
    case Picked = 'picked';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Batched => 'Batched',
            self::Picked => 'Picked',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Batched => 'info',
            self::Picked => 'success',
        };
    }
}
