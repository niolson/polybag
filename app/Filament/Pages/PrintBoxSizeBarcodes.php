<?php

namespace App\Filament\Pages;

use App\Enums\BoxSizeType;
use App\Models\BoxSize;
use App\Services\CacheService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class PrintBoxSizeBarcodes extends Page
{
    protected static ?string $title = 'Print Box Size Barcodes';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected string $view = 'filament.pages.print-box-size-barcodes';

    public Collection $boxSizes;

    /**
     * Group the sheet by type, in the enum's order, and print each group smallest first.
     */
    public function mount(): void
    {
        $typeOrder = array_flip(array_map(fn (BoxSizeType $type): string => $type->value, BoxSizeType::cases()));

        $this->boxSizes = app(CacheService::class)->getBoxSizes()
            ->sortBy([
                fn (BoxSize $a, BoxSize $b): int => $typeOrder[$a->type->value] <=> $typeOrder[$b->type->value],
                fn (BoxSize $a, BoxSize $b): int => self::volume($a) <=> self::volume($b),
                fn (BoxSize $a, BoxSize $b): int => (float) $a->length <=> (float) $b->length,
                fn (BoxSize $a, BoxSize $b): int => strnatcasecmp($a->label, $b->label),
            ])
            ->values();
    }

    private static function volume(BoxSize $boxSize): float
    {
        return (float) $boxSize->length * (float) $boxSize->width * (float) $boxSize->height;
    }
}
