<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * An import that stopped before it could finish, so no
 * {@see ImportCompleted} will follow: it ran out of memory, ran out of time,
 * or gave up waiting behind another import of the same connection.
 */
class ImportStopped extends Notification
{
    public function __construct(
        public string $sourceName,
        public string $reason,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Import Stopped ({$this->sourceName})")
            ->body($this->reason)
            ->icon('heroicon-o-x-circle')
            ->iconColor('danger')
            ->getDatabaseMessage();
    }
}
