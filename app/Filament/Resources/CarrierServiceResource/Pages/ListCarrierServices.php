<?php

namespace App\Filament\Resources\CarrierServiceResource\Pages;

use App\Filament\Resources\CarrierServiceResource;
use App\Models\CarrierService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListCarrierServices extends ListRecords
{
    protected static string $resource = CarrierServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getFooter(): ?View
    {
        $hasFedex = CarrierService::whereHas('carrier', fn ($q) => $q->where('name', 'FedEx'))->exists();

        if (! $hasFedex) {
            return null;
        }

        return view('components.legal-disclaimers', ['show' => ['fedex']]);
    }
}
