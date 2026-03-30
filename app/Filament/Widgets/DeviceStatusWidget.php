<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class DeviceStatusWidget extends Widget
{
    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.device-status';

    protected ?string $pollingInterval = null;
}
