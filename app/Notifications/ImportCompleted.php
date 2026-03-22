<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ImportCompleted extends Notification
{
    /**
     * @param  array<string, int>  $stats
     * @param  array<string>  $errors
     */
    public function __construct(
        public array $stats,
        public string $sourceName,
        public array $errors = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $hasErrors = count($this->errors) > 0;
        $created = $this->stats['shipments_created'] ?? 0;
        $updated = $this->stats['shipments_updated'] ?? 0;

        $body = "{$created} created, {$updated} updated";
        if ($hasErrors) {
            $errorCount = count($this->errors);
            $body .= " — {$errorCount} ".($errorCount === 1 ? 'error' : 'errors');
        }

        return FilamentNotification::make()
            ->title($hasErrors
                ? "Import completed with errors ({$this->sourceName})"
                : "Import completed ({$this->sourceName})")
            ->body($body)
            ->icon($hasErrors ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-arrow-down-tray')
            ->iconColor($hasErrors ? 'warning' : 'success')
            ->getDatabaseMessage();
    }
}
