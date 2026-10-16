<x-filament-widgets::widget>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950">
        <div class="flex items-start gap-4">
            <div class="mt-0.5 shrink-0 text-amber-500 dark:text-amber-400">
                <x-heroicon-o-beaker class="h-6 w-6" />
            </div>
            <div class="flex-1">
                <p class="font-semibold text-amber-900 dark:text-amber-100">
                    Demo Mode — No Real Labels or Charges
                </p>
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                    @if (config('app.fake_carriers'))
                        This instance uses sample carriers. Labels are marked SAMPLE and nothing is sent to any carrier.
                    @else
                        Carrier and Amazon calls go to sandbox accounts, so no real labels are bought and nothing is charged.
                    @endif
                </p>
                <div class="mt-3 space-y-1 text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-medium">To test label printing:</p>
                    <ol class="ml-4 list-decimal space-y-1">
                        <li>Open <strong>Workstation Settings</strong> from the user menu (top right), install the print bridge on this workstation, and run the trust installer.</li>
                        <li>Select your label printer and format, then ship a package to print a test label.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
