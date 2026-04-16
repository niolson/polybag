<?php

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Resources\LocationResource;
use App\Services\PhoneParserService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
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
