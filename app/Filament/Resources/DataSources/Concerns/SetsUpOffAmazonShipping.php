<?php

namespace App\Filament\Resources\DataSources\Concerns;

use App\DataTransferObjects\PostageSources\OffAmazonShippingCheckResult;
use App\Enums\OffAmazonShippingStatus;
use App\Models\DataSource;
use App\Services\PostageSources\OffAmazonShippingCheck;
use Filament\Notifications\Notification;

/**
 * What happens when an Amazon connection starts offering Amazon Shipping for
 * orders from other channels: it gets its default scope if it has none and the
 * slot is free, and the account is checked while the operator waits, so an
 * A-101 refusal is on screen on that save. Neither blocks the save.
 */
trait SetsUpOffAmazonShipping
{
    protected function setUpOffAmazonShipping(DataSource $source): void
    {
        if (! $source->ensureDefaultOffAmazonShippingScope()) {
            Notification::make()
                ->warning()
                ->title('No assignment for Amazon Shipping')
                ->body($source->client_id === null
                    ? 'Another connection already ships orders from other channels for all clients and locations. Add an assignment to say which orders this connection ships.'
                    : 'Another connection already ships this client\'s orders from other channels. Add an assignment to say which of its orders this connection ships.')
                ->persistent()
                ->send();
        }

        $this->notifyOffAmazonShippingCheck(app(OffAmazonShippingCheck::class)->check($source));
    }

    protected function notifyOffAmazonShippingCheck(OffAmazonShippingCheckResult $result): void
    {
        $notification = Notification::make()->body($result->message);

        match ($result->status) {
            OffAmazonShippingStatus::Enabled => $notification->success()->title('Amazon Shipping is enabled'),
            OffAmazonShippingStatus::NotSetUp => $notification->warning()->title('Amazon Shipping is not set up on this account')->persistent(),
            OffAmazonShippingStatus::Unknown => $notification->warning()->title('Amazon Shipping account not checked')->persistent(),
        };

        $notification->send();
    }
}
