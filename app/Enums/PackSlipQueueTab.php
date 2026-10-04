<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The tabs of the Print Pack Slips page. Not printed lists slips still needed,
 * including out-of-date ones; Printed lists current slips, for reprinting.
 */
enum PackSlipQueueTab: string implements HasLabel
{
    case NotPrinted = 'not_printed';
    case Printed = 'printed';

    /**
     * @return list<PackSlipState>
     */
    public function states(): array
    {
        return match ($this) {
            self::NotPrinted => [PackSlipState::NotPrinted, PackSlipState::ChangedSincePrinted],
            self::Printed => [PackSlipState::Printed],
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::NotPrinted => 'Not printed',
            self::Printed => 'Printed',
        };
    }
}
