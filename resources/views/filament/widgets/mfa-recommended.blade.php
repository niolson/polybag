<x-filament-widgets::widget>
    @unless ($dismissed)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950">
            <div class="flex items-start gap-4">
                <div class="mt-0.5 shrink-0 text-amber-500 dark:text-amber-400">
                    <x-heroicon-o-shield-exclamation class="h-6 w-6" />
                </div>
                <div class="flex-1">
                    <p class="font-semibold text-amber-900 dark:text-amber-100">
                        Multi-factor authentication is off
                    </p>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                        Admin accounts can configure connections, carrier credentials, and see every client's data. Requiring MFA protects these accounts if a password is ever phished or reused.
                    </p>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                        If your admins sign in with Google or Microsoft, MFA may already be enforced by that provider — in which case you can safely dismiss this.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                        <a href="{{ \App\Filament\Pages\Settings::getUrl() }}"
                           class="font-medium text-amber-900 underline hover:no-underline dark:text-amber-100">
                            Enable it in Settings → Authentication
                        </a>
                        <button type="button"
                                wire:click="dismiss"
                                class="font-medium text-amber-700 underline hover:no-underline dark:text-amber-300">
                            Don't show this again
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endunless
</x-filament-widgets::widget>
