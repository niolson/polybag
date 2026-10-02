<?php

namespace App\Filament\Support;

use App\DataTransferObjects\PackageLabels\LabelVoidResult;
use Filament\Notifications\Notification;

class LabelVoidNotification
{
    /**
     * Tell the operator how a void went, and separately what it left for them to fix.
     *
     * The warning stays on screen until dismissed: it names work to do in
     * another system, which a toast that fades would let slip.
     */
    public static function send(LabelVoidResult $result, ?string $successTitle = null): void
    {
        $notification = Notification::make()
            ->title($result->success ? ($successTitle ?? $result->title) : $result->title)
            ->body($result->message);

        $result->success
            ? $notification->success()->send()
            : $notification->danger()->send();

        if ($result->warning !== null) {
            Notification::make()
                ->title('Sales channel not updated')
                ->body($result->warning)
                ->warning()
                ->persistent()
                ->send();
        }
    }
}
