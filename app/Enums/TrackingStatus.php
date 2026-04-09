<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum TrackingStatus: string implements HasColor, HasLabel
{
    case PreTransit = 'pre_transit';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Exception = 'exception';
    case Returned = 'returned';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::PreTransit => 'Pre-Transit',
            self::InTransit => 'In Transit',
            self::OutForDelivery => 'Out for Delivery',
            self::Delivered => 'Delivered',
            self::Exception => 'Exception',
            self::Returned => 'Returned',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PreTransit => 'gray',
            self::InTransit => 'info',
            self::OutForDelivery => 'warning',
            self::Delivered => 'success',
            self::Exception => 'danger',
            self::Returned => 'warning',
        };
    }
}
