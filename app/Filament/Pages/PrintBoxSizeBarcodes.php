<?php

namespace App\Filament\Pages;

use App\Services\CacheService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class PrintBoxSizeBarcodes extends Page
{
    protected static ?string $title = 'Print Box Size Barcodes';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.print-box-size-barcodes';

    public Collection $boxSizes;

    public function mount(): void
    {
        $this->boxSizes = CacheService::getBoxSizes();
    }
}
