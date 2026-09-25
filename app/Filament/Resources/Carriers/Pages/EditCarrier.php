<?php

namespace App\Filament\Resources\Carriers\Pages;

use App\Filament\Resources\Carriers\CarrierResource;
use App\Models\Carrier;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCarrier extends EditRecord
{
    protected static string $resource = CarrierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (): bool => $this->carrier()->is_system)
                ->before(function (DeleteAction $action): void {
                    if ($this->carrier()->normalizedPackages()->exists()) {
                        Notification::make()
                            ->title('Cannot delete carrier')
                            ->body('This carrier is recorded on shipped packages. Deactivate it instead.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    public function getRecordTitle(): string
    {
        return $this->carrier()->label();
    }

    private function carrier(): Carrier
    {
        $record = $this->getRecord();

        if (! $record instanceof Carrier) {
            throw new \LogicException('The Carrier record is unavailable.');
        }

        return $record;
    }
}
