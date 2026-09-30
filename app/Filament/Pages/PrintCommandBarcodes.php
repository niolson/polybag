<?php

namespace App\Filament\Pages;

use App\Enums\ScanCommand;
use App\Services\Scanning\ScanCode;
use Filament\Pages\Page;

class PrintCommandBarcodes extends Page
{
    protected static ?string $title = 'Print Command Barcodes';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected string $view = 'filament.pages.print-command-barcodes';

    public array $commands = [];

    public function mount(): void
    {
        $this->commands = collect(ScanCommand::cases())
            ->map(fn (ScanCommand $command): array => [
                'code' => ScanCode::forCommand($command),
                'label' => $command->getLabel(),
                'description' => $command->getDescription(),
            ])
            ->all();
    }
}
