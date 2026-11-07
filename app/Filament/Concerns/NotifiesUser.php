<?php

namespace App\Filament\Concerns;

use App\Filament\Support\LabelBoughtMessage;
use App\Models\Package;
use Filament\Notifications\Notification;

/**
 * Provides convenient notification helper methods for Filament pages.
 */
trait NotifiesUser
{
    /**
     * Say a Label was bought for the package, with its carrier's logo and service.
     */
    protected function notifyLabelBought(string $title, Package $package): void
    {
        $this->notifySuccess($title, LabelBoughtMessage::for($package));
    }

    protected function notifySuccess(string $title, ?string $body = null): void
    {
        $notification = Notification::make()
            ->title($title)
            ->success();

        if ($body) {
            $notification->body($body);
        }

        $notification->send();
    }

    protected function notifyError(string $title, ?string $body = null): void
    {
        $notification = Notification::make()
            ->title($title)
            ->danger();

        if ($body) {
            $notification->body($body);
        }

        $notification->send();
    }

    protected function notifyWarning(string $title, ?string $body = null): void
    {
        $notification = Notification::make()
            ->title($title)
            ->warning();

        if ($body) {
            $notification->body($body);
        }

        $notification->send();
    }

    protected function notifyInfo(string $title, ?string $body = null): void
    {
        $notification = Notification::make()
            ->title($title)
            ->info();

        if ($body) {
            $notification->body($body);
        }

        $notification->send();
    }
}
