<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Enums\PackageStatus;
use App\Filament\Resources\PackageResource;
use App\Models\Package;
use App\Services\PostageSources\OfferStore;
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
                            ->body('This package has been shipped. Void the label first.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }

                    if ($this->record instanceof Package && app(OfferStore::class)->hasUnresolvedPurchase($this->record)) {
                        Notification::make()
                            ->title('Cannot delete package')
                            ->body('A label purchase for this package has not been accounted for, so a label may exist. Settle it on the package page first.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
