<?php

namespace App\Filament\Concerns;

use App\Services\OAuthService;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * For a create page whose Create leads into OAuth. Pressing Back at the
 * provider's consent screen reloads this page: the record already exists, and
 * the browser refills inputs the form no longer holds, so a second Create fails
 * as if they were empty. Instead, the page returns to the record it created, as
 * long as the trip has not finished. The OAuth callback clears the marker.
 */
trait ReturnsToRecordAwaitingOAuth
{
    /**
     * Whether the record's OAuth trip has finished, so there is nothing to return to.
     */
    abstract protected function isConnectedThroughOAuth(Model $record): bool;

    /**
     * Call when Create has sent the operator to the provider.
     */
    protected function rememberRecordAwaitingOAuth(Model $record, string $providerName): void
    {
        session()->put(OAuthService::AWAITING_RECORD_SESSION_KEY, [
            'page' => static::class,
            'id' => $record->getKey(),
            'provider' => $providerName,
        ]);
    }

    /**
     * Call from mount(): redirects to the record created here and still awaiting
     * OAuth, once.
     */
    protected function returnToRecordAwaitingOAuth(): void
    {
        $awaiting = session()->get(OAuthService::AWAITING_RECORD_SESSION_KEY);

        if (($awaiting['page'] ?? null) !== static::class) {
            return;
        }

        session()->forget(OAuthService::AWAITING_RECORD_SESSION_KEY);

        $record = static::getModel()::find($awaiting['id'] ?? null);

        if ($record === null || $this->isConnectedThroughOAuth($record)) {
            return;
        }

        Notification::make()
            ->warning()
            ->title("{$record->getAttribute('name')} is not connected to {$awaiting['provider']} yet.")
            ->body("Use Connect {$awaiting['provider']} to finish.")
            ->send();

        $this->redirect(static::getResource()::getUrl('edit', ['record' => $record]));
    }
}
