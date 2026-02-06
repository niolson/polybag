<?php

namespace App\Filament\Resources\ShipmentResource\Pages;

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
            Actions\Action::make('validateAddress')
                ->label('Validate Address')
                ->icon('heroicon-o-shield-check')
                ->color('success')
                ->action(function (): void {
                    $this->record->validateAddress();
                    $this->record->refresh();
                    $this->fillForm();

                    Notification::make()
                        ->title('Address validated')
                        ->body($this->record->validation_message ?? 'Validation complete')
                        ->success()
                        ->send();
                }),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        foreach (['address1', 'address2', 'city', 'state', 'zip', 'country'] as $field) {
            if (($data[$field] ?? null) !== $record->$field) {
                $data['checked'] = false;
                break;
            }
        }

        return $data;
    }
}
