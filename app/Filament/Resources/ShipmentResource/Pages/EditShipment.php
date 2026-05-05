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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        foreach (['address1', 'address2', 'city', 'state_or_province', 'postal_code', 'country'] as $field) {
            if (($data[$field] ?? null) !== $record->$field) {
                $data['checked'] = false;
                break;
            }
        }

        return $data;
    }
}
