<?php

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Resources\LocationResource;
use App\Models\Package;
use App\Services\PhoneParserService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action) {
                    if (Package::where('location_id', $this->record->id)->exists()) {
                        Notification::make()
                            ->title('Cannot delete location')
                            ->body('This location is referenced by existing packages.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validatePhoneNumber($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validatePhoneNumber(array $data): void
    {
        $phone = filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null;

        if ($phone === null) {
            return;
        }

        $country = (string) ($data['country'] ?? 'US');
        $result = PhoneParserService::parse($phone, $country);

        if ($result->isValid()) {
            return;
        }

        $message = "Enter a valid phone number for {$country}. If the phone number belongs to a different country, use international format, such as +14155550132.";

        Notification::make()
            ->title('Invalid phone number')
            ->body($message)
            ->danger()
            ->send();

        $this->addError('data.phone', $message);

        throw new Halt;
    }
}
