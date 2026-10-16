<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Printer, scale and label-format settings, kept in this browser's storage rather
 * than on the user. Reached from the user menu, not the sidebar.
 */
class DeviceSettings extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Workstation Settings';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected string $view = 'filament.pages.device-settings';
}
