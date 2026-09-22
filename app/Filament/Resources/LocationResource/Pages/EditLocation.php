<?php

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Resources\LocationResource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
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
                ->before(function (Actions\DeleteAction $action): void {
                    $locationId = $this->location()->id;

                    if (Package::where('location_id', $this->record->id)->exists()
                        || Shipment::where('location_id', $locationId)->exists()
                        || DataSourceLocation::where('location_id', $locationId)->exists()) {
                        Notification::make()
                            ->title('Cannot delete location')
                            ->body('This location is referenced by existing shipments, packages, or Connection location mappings. Deactivate it instead.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    private function location(): Location
    {
        $record = $this->getRecord();

        if (! $record instanceof Location) {
            throw new \LogicException('The Location record is unavailable.');
        }

        return $record;
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
