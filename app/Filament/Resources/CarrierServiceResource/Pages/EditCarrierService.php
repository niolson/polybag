<?php

namespace App\Filament\Resources\CarrierServiceResource\Pages;

use App\Filament\Resources\CarrierServiceResource;
use App\Models\ShippingRule;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCarrierService extends EditRecord
{
    protected static string $resource = CarrierServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action) {
                    if (ShippingRule::where('carrier_service_id', $this->record->id)->exists()) {
                        Notification::make()
                            ->title('Cannot delete carrier service')
                            ->body('This carrier service has shipping rules. Remove the rules first.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
