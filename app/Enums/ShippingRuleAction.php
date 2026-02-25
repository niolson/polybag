<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ShippingRuleAction: string implements HasLabel
{
    case UseService = 'use_service';
    case ExcludeService = 'exclude_service';

    public function getLabel(): string
    {
        return match ($this) {
            self::UseService => 'Use Service',
            self::ExcludeService => 'Exclude Service',
        };
    }
}
