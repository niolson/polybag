<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UspsProcessingCategory: string implements HasLabel
{
    case CARDS = 'CARDS';
    case LETTERS = 'LETTERS';
    case FLATS = 'FLATS';
    case MACHINABLE = 'MACHINABLE'; // most standard boxes, padded mailers, polybags
    case IRREGULAR = 'IRREGULAR'; // deprecated, use nonstandard
    case NON_MACHINABLE = 'NON_MACHINABLE'; // deprecated, use nonstandard
    case NONSTANDARD = 'NONSTANDARD';
    case CATALOGS = 'CATALOGS';
    case OPEN_AND_DISTRIBUTE = 'OPEN_AND_DISTRIBUTE';
    case RETURNS = 'RETURNS';
    case SOFT_PACK_MACHINABLE = 'SOFT_PACK_MACHINABLE'; // not returned by the shipping options api? api returns MACHINABLE for soft pack machinable
    case SOFT_PACK_NON_MACHINABLE = 'SOFT_PACK_NON_MACHINABLE'; // not returned by the shipping options api?

    public function getLabel(): string
    {
        return match ($this) {
            self::CARDS => 'Cards',
            self::LETTERS => 'Letters',
            self::FLATS => 'Flats',
            self::MACHINABLE => 'Machinable',
            self::IRREGULAR => 'Irregular',
            self::NON_MACHINABLE => 'Non-Machinable',
            self::NONSTANDARD => 'Nonstandard',
            self::CATALOGS => 'Catalogs',
            self::OPEN_AND_DISTRIBUTE => 'Open and Distribute',
            self::RETURNS => 'Returns',
            self::SOFT_PACK_MACHINABLE => 'Soft Pack Machinable',
            self::SOFT_PACK_NON_MACHINABLE => 'Soft Pack Non-Machinable',
        };
    }
}
