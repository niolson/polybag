<?php

namespace App\Notifications;

use App\Filament\Resources\PackageResource;
use App\Models\Package;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * A source sold a label that PolyBag could not record. Kept in the database
 * rather than only toasted: the packer may miss a toast, and until someone
 * retries or voids it the label is paid for and nowhere in PolyBag.
 */
class LabelNotRecorded extends Notification
{
    public function __construct(
        public Package $package,
        public string $seller,
        public ?string $trackingNumber,
        public bool $recoverable,
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
        $label = $this->trackingNumber !== null ? "label {$this->trackingNumber}" : 'a label';

        return FilamentNotification::make()
            ->title("Label bought but not recorded for package {$this->package->id}")
            ->body($this->recoverable
                ? "{$this->seller} sold {$label}, but PolyBag could not save it. "
                    ."Buying again for this package asks {$this->seller} for this label first. "
                    ."If that keeps failing, void it with {$this->seller}."
                : "{$this->seller} sold {$label}, but PolyBag could not save it and cannot ask {$this->seller} for it again. "
                    ."Void it with {$this->seller} before anyone buys another label for this package.")
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('danger')
            ->actions([
                Action::make('view')
                    ->label('View Package')
                    ->url(PackageResource::getUrl('view', ['record' => $this->package])),
            ])
            ->getDatabaseMessage();
    }
}
