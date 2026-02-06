<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FedexPackageType: string implements HasLabel
{
    case YOUR_PACKAGING = 'YOUR_PACKAGING';
    case FEDEX_ENVELOPE = 'FEDEX_ENVELOPE';
    case FEDEX_BOX = 'FEDEX_BOX';
    case FEDEX_SMALL_BOX = 'FEDEX_SMALL_BOX';
    case FEDEX_MEDIUM_BOX = 'FEDEX_MEDIUM_BOX';
    case FEDEX_LARGE_BOX = 'FEDEX_LARGE_BOX';
    case FEDEX_EXTRA_LARGE_BOX = 'FEDEX_EXTRA_LARGE_BOX';
    case FEDEX_10KG_BOX = 'FEDEX_10KG_BOX';
    case FEDEX_25KG_BOX = 'FEDEX_25KG_BOX';
    case FEDEX_PAK = 'FEDEX_PAK';
    case FEDEX_TUBE = 'FEDEX_TUBE';

    public function getLabel(): string
    {
        return match ($this) {
            self::YOUR_PACKAGING => 'Your Packaging',
            self::FEDEX_ENVELOPE => 'FedEx Envelope',
            self::FEDEX_BOX => 'FedEx Box',
            self::FEDEX_SMALL_BOX => 'FedEx Small Box',
            self::FEDEX_MEDIUM_BOX => 'FedEx Medium Box',
            self::FEDEX_LARGE_BOX => 'FedEx Large Box',
            self::FEDEX_EXTRA_LARGE_BOX => 'FedEx Extra Large Box',
            self::FEDEX_10KG_BOX => 'FedEx 10kg Box',
            self::FEDEX_25KG_BOX => 'FedEx 25kg Box',
            self::FEDEX_PAK => 'FedEx Pak',
            self::FEDEX_TUBE => 'FedEx Tube',
        };
    }
}
