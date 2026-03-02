<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Resources\LabelBatchResource;
use BackedEnum;
use Filament\Pages\Page;

class BatchShipResults extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'batch-ship/{labelBatchId}';

    protected string $view = 'filament.pages.batch-ship-results';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Admin) ?? false;
    }

    public function mount(int $labelBatchId): void
    {
        redirect(LabelBatchResource::getUrl('view', ['record' => $labelBatchId]));
    }
}
