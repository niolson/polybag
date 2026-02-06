<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class PrintCommandBarcodes extends Page
{
    protected static ?string $title = 'Print Command Barcodes';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.print-command-barcodes';

    public array $commands = [];

    public function mount(): void
    {
        $this->commands = [
            ['code' => '*1', 'label' => 'Ship Package', 'description' => 'Ship the current package (same as F12)'],
            ['code' => '*2', 'label' => 'Reprint Last Label', 'description' => 'Reprint the last shipped label'],
            ['code' => '*3', 'label' => 'Cancel Last Label', 'description' => 'Void/cancel the last shipped label'],
            ['code' => '*0', 'label' => 'Clear Shipment', 'description' => 'Clear current shipment and start fresh'],
        ];
    }
}
