<x-filament-widgets::widget>
    <div
        x-data="{
            printer: null,
            scaleConfigured: false,
            isShipper: @js(auth()->user()?->role === \App\Enums\Role::User),

            init() {
                this.printer = PrinterSettings.labelPrinterFor(PrinterSettings.labelFormat())
                this.scaleConfigured = !!localStorage.getItem('scaleProductId')
            },

            get printerStatus() {
                if (!this.printer) return 'Not selected'
                return this.printer
            },

            get printerOk() {
                return !!this.printer
            },

            get scaleStatus() {
                if (!this.scaleConfigured) return 'Not configured'
                return 'Configured'
            },

            get scaleOk() {
                return this.scaleConfigured
            },

            get allOk() {
                return this.printerOk && this.scaleOk
            }
        }"
        x-show="!allOk || isShipper"
        x-cloak
        class="fi-wi-stats-overview-stat relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    >
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-6">
                {{-- Printer Status --}}
                <div class="flex items-center gap-2">
                    <div
                        class="w-2 h-2 rounded-full"
                        :class="printerOk ? 'bg-emerald-500' : 'bg-amber-500'"
                    ></div>
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        <span class="font-medium text-gray-900 dark:text-gray-100">Printer:</span>
                        <span x-text="printerStatus"></span>
                    </span>
                </div>

                {{-- Scale Status --}}
                <div class="flex items-center gap-2">
                    <div
                        class="w-2 h-2 rounded-full"
                        :class="scaleOk ? 'bg-emerald-500' : 'bg-amber-500'"
                    ></div>
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        <span class="font-medium text-gray-900 dark:text-gray-100">Scale:</span>
                        <span x-text="scaleStatus"></span>
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-4">
                @if(auth()->user()?->role === \App\Enums\Role::User)
                    <a
                        href="{{ \App\Filament\Pages\Pack::getUrl() }}"
                        class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                    >
                        Start packing
                    </a>
                @endif

                <a
                    x-show="!allOk"
                    x-cloak
                    href="{{ \App\Filament\Pages\DeviceSettings::getUrl() }}"
                    class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                >
                    Set up printer and scale &rarr;
                </a>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
