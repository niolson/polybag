<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\LabelBatch;
use App\Models\Package;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action): void {
                    $hasPackages = Package::where('shipped_by_user_id', $this->record->id)->exists();
                    $hasBatches = LabelBatch::where('user_id', $this->record->id)->exists();

                    if ($hasPackages || $hasBatches) {
                        Notification::make()
                            ->title('Cannot delete user')
                            ->body('This user has shipping history.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
