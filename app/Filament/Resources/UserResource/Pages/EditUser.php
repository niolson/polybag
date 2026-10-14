<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\LabelBatch;
use App\Models\Package;
use App\Models\User;
use App\Services\MfaResetService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resetAppAuthentication')
                ->authorize('update')
                ->label('Reset Authenticator App')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('This clears the authenticator app enrollment for this user. They will need to set it up again from their profile — use this if they lost their device.')
                ->visible(fn (User $record): bool => app(MfaResetService::class)->hasAppAuthentication($record))
                ->action(function (User $record): void {
                    app(MfaResetService::class)->resetAppAuthentication($record);

                    Notification::make()
                        ->title('Authenticator app reset')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('resetEmailAuthentication')
                ->authorize('update')
                ->label('Reset Email Code')
                ->icon('heroicon-o-envelope')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('This disables email code authentication for this user. They will need to set it up again from their profile — use this if they lost access to their email.')
                ->visible(fn (User $record): bool => app(MfaResetService::class)->hasEmailAuthentication($record))
                ->action(function (User $record): void {
                    app(MfaResetService::class)->resetEmailAuthentication($record);

                    Notification::make()
                        ->title('Email code authentication reset')
                        ->success()
                        ->send();
                }),
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
