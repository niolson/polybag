<?php

namespace App\Filament\Resources\ShipmentResource\Pages;

use App\Enums\PackageStatus;
use App\Filament\Resources\ShipmentResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditShipment extends EditRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action): void {
                    if ($this->record->packages()->where('status', PackageStatus::Shipped)->exists()) {
                        Notification::make()
                            ->title('Cannot delete shipment')
                            ->body('This shipment has shipped packages. Void the labels first before deleting.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
