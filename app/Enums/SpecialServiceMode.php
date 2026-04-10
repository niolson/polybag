<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SpecialServiceMode: string implements HasLabel
{
    /** The service can be selected by the operator on the ship page. */
    case Available = 'available';

    /** The service is pre-selected by default but can be removed. */
    case Default = 'default';

    /** The service is always applied and cannot be removed. */
    case Required = 'required';

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Default => 'Default',
            self::Required => 'Required',
        };
    }
}
