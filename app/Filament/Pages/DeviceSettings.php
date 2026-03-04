<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class DeviceSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-computer-desktop';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected static ?string $navigationLabel = 'Device Settings';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.device-settings';
}
