<x-filament-panels::page>
    <x-qz-tray />
    <x-scale-script />

    <div
        x-data="{
            scaleConnected: false,
            scaleStable: true,
            scaleUnderZero: false,
            scaleReadingIsZero: false,
            lastScaleWeight: null,
            minimumWeight: @js(\App\DataTransferObjects\PackageDrafts\Measurements::MINIMUM_SCALE_WEIGHT),
            autoShipEnabled: @js($autoShipEnabled),

            // A steady 0.00 from the scale, still in Weight untouched, means the
            // package is too light to read: it ships at the minimum weight, as on
            // Pack. A typed or cleared weight never qualifies.
            usesMinimumWeight() {
                return this.scaleConnected
                    && this.scaleReadingIsZero
                    && String($wire.data.weight) === this.lastScaleWeight;
            },

            init() {
                $wire.set('labelFormat', PrinterSettings.labelFormat());
                $wire.set('labelDpi', PrinterSettings.labelDpi());
                $wire.set('hasReportPrinter', PrinterSettings.hasDocumentPrinter());
                window.QzPrinterStatus.bindLabelPrinter($wire);

                if (ScaleUtils.backend === 'webhid') {
                    this.autoConnectScale();
                } else {
                    document.addEventListener('qz-tray:connected', () => this.autoConnectScale());
                }
            },

            async autoConnectScale() {
                const deviceInfo = ScaleUtils.getScaleDeviceInfo();
                if (!deviceInfo) return;

                try {
                    await ScaleUtils.claimScale();

                    let prevStable = null;
                    await ScaleUtils.startScaleStream((result) => {
                        this.scaleStable = result.isStable;
                        this.scaleUnderZero = result.weight < 0;
                        this.scaleReadingIsZero = result.isStable && result.weight >= 0 && result.weight.toFixed(2) === '0.00';

                        // Write every reading, zero and negative included, as Pack
                        // does, but only when it changes, so a weight typed over a
                        // steady reading stays.
                        const formatted = result.weight.toFixed(2);
                        if (formatted !== this.lastScaleWeight) {
                            this.lastScaleWeight = formatted;
                            $wire.data.weight = formatted;
                        }

                        // Resync the saved-data hash when the weight settles so
                        // scale autofill doesn't trigger the unsaved-changes alert.
                        if (result.isStable && prevStable !== true) {
                            $wire.syncDataHash();
                        }

                        prevStable = result.isStable;
                    });

                    this.scaleConnected = true;
                } catch (error) {
                    console.error('Failed to auto-connect scale:', error);
                }
            }
        }"
        x-effect="$wire.scaleReadsTooLight = usesMinimumWeight()"
    >
        <div class="sticky top-0 z-10 mb-4">
            <div class="flex flex-wrap items-center justify-end gap-3">
            <button
                type="button"
                wire:click="reprintLastLabel"
                wire:loading.attr="disabled"
                class="fi-btn fi-color-custom fi-btn-color-gray fi-color-gray fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-white text-gray-950 hover:bg-gray-50 focus-visible:ring-primary-600/20 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20 disabled:opacity-50 disabled:pointer-events-none"
            >
                <x-filament::icon icon="heroicon-o-printer" class="fi-btn-icon h-5 w-5" />
                <span>Reprint Label</span>
            </button>

            <x-shipping-submit-button
                type="submit"
                form="manual-ship-form"
                wire:loading.attr="disabled"
                wire:target="ship"
                loading-type="wire"
                loading-target="ship"
                :label="$scanToAddEnabled ? 'Pack' : null"
            />
            </div>
        </div>

        <form id="manual-ship-form" wire:submit="ship" class="space-y-6">
            {{ $this->form }}

            </form>
    </div>
</x-filament-panels::page>
