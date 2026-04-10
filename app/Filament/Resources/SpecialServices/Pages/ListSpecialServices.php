<?php

namespace App\Filament\Resources\SpecialServices\Pages;

use App\Filament\Resources\SpecialServices\SpecialServiceResource;
use Filament\Resources\Pages\ListRecords;

class ListSpecialServices extends ListRecords
{
    protected static string $resource = SpecialServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
