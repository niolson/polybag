<?php

namespace App\Filament\Concerns;

use App\DataTransferObjects\PrintRequest;
use App\Services\SettingsService;
use Filament\Notifications\Notification;

/**
 * Provides dispatchPrint() for Filament pages that send labels to QZ Tray.
 * Requires the using class to also use NotifiesUser (for the suppressed sandbox notification).
 */
trait PrintsLabels
{
    /**
     * Dispatch a print-label browser event, respecting the suppress_printing setting.
     *
     * When suppressed: shows a sandbox-mode notification and redirects if a URL is given.
     * When not suppressed: dispatches the event (QZ Tray JS picks it up; redirectTo is
     * handled client-side after printing completes).
     */
    protected function dispatchPrint(PrintRequest $request, ?string $redirectTo = null): void
    {
        if (app(SettingsService::class)->get('suppress_printing', false)) {
            Notification::make()
                ->title('Label printing suppressed (sandbox mode)')
                ->info()
                ->send();

            if ($redirectTo) {
                $this->redirect($redirectTo);
            }

            return;
        }

        $params = [
            'label' => $request->label,
            'orientation' => $request->orientation,
            'format' => $request->format,
            'dpi' => $request->dpi,
        ];

        if ($redirectTo !== null) {
            $params['redirectTo'] = $redirectTo;
        }

        $this->dispatch('print-label', ...$params);
    }
}
