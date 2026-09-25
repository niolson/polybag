<?php

namespace App\Filament\Resources\CarrierServiceResource\Pages;

use App\Filament\Resources\CarrierServiceResource;
use App\Models\ShippingRule;
use App\Models\SourceServiceMapping;
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
                ->before(function (Actions\DeleteAction $action): void {
                    if (ShippingRule::where('carrier_service_id', $this->record->id)->exists()) {
                        Notification::make()
                            ->title('Cannot delete carrier service')
                            ->body('This carrier service has shipping rules. Remove the rules first.')
                            ->danger()
                            ->send();

                        $action->cancel();

                        return;
                    }

                    // The mapping's foreign key restricts deletion; refuse here
                    // so the person reads why instead of a database error.
                    $mapping = SourceServiceMapping::query()->where('carrier_service_id', $this->getRecord()->getKey())->first();

                    if ($mapping instanceof SourceServiceMapping) {
                        Notification::make()
                            ->title('Cannot delete carrier service')
                            ->body("{$mapping->describe()} is mapped to this service. Unmap it on Map Carrier Services, or deactivate the service instead.")
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
