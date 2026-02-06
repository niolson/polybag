<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BoxSizeType: string implements HasLabel
{
    case BOX = 'BOX';
    case POLYBAG = 'POLYBAG';
    case PADDED_MAILER = 'PADDED_MAILER';

    public function getLabel(): string
    {
        return match ($this) {
            self::BOX => 'Box',
            self::POLYBAG => 'Polybag',
            self::PADDED_MAILER => 'Padded Mailer',
        };
    }
}
