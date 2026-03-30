<x-filament-widgets::widget>
    <div
        x-data="{
            printer: null,
            scaleConfigured: false,

            init() {
                this.printer = localStorage.getItem('labelPrinter') || null
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
        x-show="!allOk"
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

            {{-- Link to Device Settings --}}
            <a
                href="{{ \App\Filament\Pages\DeviceSettings::getUrl() }}"
                class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
            >
                Configure devices &rarr;
            </a>
        </div>
    </div>
</x-filament-widgets::widget>
