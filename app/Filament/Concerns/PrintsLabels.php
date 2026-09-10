<?php

namespace App\Filament\Concerns;

use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\PrintRequest;
use App\Models\Package;
use App\Services\SettingsService;
use Filament\Notifications\Notification;

/**
 * Provides dispatchPrint() for Filament pages that send labels to QZ Tray.
 * Requires the using class to also use NotifiesUser (for the suppressed sandbox notification).
 */
trait PrintsLabels
{
    /**
     * Reprint a package's stored label.
     *
     * Public because Filament table actions dispatch through the host page, which
     * means it is reachable from the browser — so it takes only an id and runs the
     * same access check as any other reprint rather than trusting caller-supplied
     * label data.
     */
    public function printStoredPackageLabel(int $packageId): void
    {
        $package = Package::find($packageId);

        if (! $package) {
            Notification::make()->danger()->title('Package Not Found')->send();

            return;
        }

        $result = app(PackageLabelWorkflow::class)->labelForReprint($package, auth()->user());

        if (! $result->success) {
            Notification::make()->danger()->title($result->title)->body($result->message)->send();

            return;
        }

        $this->dispatchPrint($result->printRequest);
    }

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
            'packageId' => $request->packageId,
        ];

        // Only sent where there is one, so the listener can tell "no customs
        // document" from "a customs document that did not print".
        if ($request->customsForm !== null) {
            $params['customsForm'] = $request->customsForm;
        }

        if ($redirectTo !== null) {
            $params['redirectTo'] = $redirectTo;
        }

        $this->dispatch('print-label', ...$params);
    }
}
