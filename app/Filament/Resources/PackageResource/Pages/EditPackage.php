<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Enums\PackageStatus;
use App\Filament\Resources\PackageResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action): void {
                    if ($this->record->status === PackageStatus::Shipped) {
                        Notification::make()
                            ->title('Cannot delete package')
                            ->body('This package has been shipped. Void the label first before deleting.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
