<?php

namespace App\Notifications;

use App\Enums\TrackingStatus;
use App\Filament\Resources\PackageResource;
use App\Models\Package;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class TrackingExceptionDetected extends Notification
{
    public function __construct(
        public Package $package,
        public string $reason,
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
        $trackingNumber = $this->package->tracking_number ?? 'Unknown';
        $title = $this->package->tracking_status === TrackingStatus::Exception
            ? "Tracking exception for {$trackingNumber}"
            : "Tracking needs attention for {$trackingNumber}";

        return FilamentNotification::make()
            ->title($title)
            ->body($this->reason)
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('warning')
            ->actions([
                Action::make('view')
                    ->label('View Package')
                    ->url(PackageResource::getUrl('view', ['record' => $this->package])),
            ])
            ->getDatabaseMessage();
    }
}
