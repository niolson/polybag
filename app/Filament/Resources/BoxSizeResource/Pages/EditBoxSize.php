<?php

namespace App\Filament\Resources\BoxSizeResource\Pages;

use App\Filament\Resources\BoxSizeResource;
use App\Models\Package;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBoxSize extends EditRecord
{
    protected static string $resource = BoxSizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action): void {
                    if (Package::where('box_size_id', $this->record->id)->exists()) {
                        Notification::make()
                            ->title('Cannot delete box size')
                            ->body('This box size is referenced by existing packages.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
