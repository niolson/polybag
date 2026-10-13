<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

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

        $closed = $this->stats['shipments_closed'] ?? 0;

        $body = "{$created} created, {$updated} updated".($closed > 0 ? ", {$closed} closed by the source" : '');
        if ($hasErrors) {
            $errorCount = count($this->errors);
            $body .= " — {$errorCount} ".($errorCount === 1 ? 'error' : 'errors').': '.Str::limit($this->errors[0], 150);

            if ($errorCount > 1) {
                $body .= ' (+'.($errorCount - 1).' more, see shipment-import log)';
            }
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
