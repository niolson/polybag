<?php

namespace App\Filament\Widgets;

use App\Enums\AppMode;
use Filament\Widgets\Widget;

class DemoModeWidget extends Widget
{
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.demo-mode';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return AppMode::current() === AppMode::Demo;
    }
}
