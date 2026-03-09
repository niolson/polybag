<?php

namespace App\Filament\Resources\ShipmentResource\Pages;

use App\Enums\Deliverability;
use App\Filament\Resources\ShipmentResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
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

                    if ($this->record->deliverability === Deliverability::NotChecked) {
                        Notification::make()
                            ->title('Address not checked')
                            ->body('No address validator available for this country.')
                            ->info()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Address validated')
                            ->body($this->record->validation_message ?? 'Validation complete')
                            ->success()
                            ->send();
                    }
                }),
            Actions\Action::make('pack')
                ->label('Pack')
                ->icon('heroicon-o-archive-box')
                ->color('primary')
                ->url(fn () => '/pack/'.$this->record->id),
            Actions\EditAction::make(),
        ];
    }
}
