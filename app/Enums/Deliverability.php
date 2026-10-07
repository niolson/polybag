<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What the validation evidence supports about an address, strongest first.
 *
 * Yes is a confirmed delivery point; Verified is a reference-data match with
 * no delivery-point data; Partial matched except for part of the address;
 * Unverified means every validator was tried and none could settle it; No is
 * positive evidence the address is wrong.
 */
enum Deliverability: string implements HasColor, HasIcon, HasLabel
{
    case Yes = 'yes';
    case Verified = 'verified';
    case Partial = 'partial';
    case Unverified = 'unverified';
    case No = 'no';
    case NotChecked = 'not_checked';

    /**
     * Results that need no attention before shipping.
     *
     * @return list<self>
     */
    public static function confirmed(): array
    {
        return [self::Yes, self::Verified];
    }

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Yes => 'Deliverable',
            self::Verified => 'Verified',
            self::Partial => 'Partly verified',
            self::Unverified => "Couldn't verify",
            self::No => 'Not deliverable',
            self::NotChecked => 'Not Checked',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Yes, self::Verified => 'success',
            self::Partial => 'warning',
            self::Unverified, self::NotChecked => 'gray',
            self::No => 'danger',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Yes => Heroicon::CheckCircle,
            self::Verified => Heroicon::ShieldCheck,
            self::Partial => Heroicon::ExclamationTriangle,
            self::Unverified, self::NotChecked => Heroicon::QuestionMarkCircle,
            self::No => Heroicon::XCircle,
        };
    }
}
