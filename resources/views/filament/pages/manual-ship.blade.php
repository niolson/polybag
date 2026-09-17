@php
    $isAdmin = auth()->user()->role->isAtLeast(\App\Enums\Role::Admin);
@endphp

<x-filament-panels::page>
    <x-qz-tray />
    <x-scale-script />

    <div
        x-data="{
            scaleConnected: false,
            scaleStable: true,
            autoShipEnabled: false,

            init() {
                const storedAutoShip = localStorage.getItem('manualShipAutoShip');
                this.autoShipEnabled = storedAutoShip === 'true';
                $wire.set('autoShipEnabled', this.autoShipEnabled);

                $wire.set('labelFormat', PrinterSettings.labelFormat());
                $wire.set('labelDpi', PrinterSettings.labelDpi());
                $wire.set('hasReportPrinter', PrinterSettings.hasDocumentPrinter());

                this.$watch('autoShipEnabled', (value) => {
                    localStorage.setItem('manualShipAutoShip', value.toString());
                    $wire.set('autoShipEnabled', value);
                });

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

                        if (result.weight > 0) {
                            $wire.data.weight = result.weight.toFixed(2);
                        }

                        // Resync the saved-data hash when the weight settles so
                        // scale autofill doesn't trigger the unsaved-changes alert.
                        if (result.isStable && prevStable !== true && result.weight > 0) {
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
    >
        <div class="sticky top-0 z-10 mb-4">
            <div class="flex flex-wrap items-center justify-end gap-3">
            @if($isAdmin)
                <x-shipping-auto-ship-toggle
                    x-on:click="autoShipEnabled = !autoShipEnabled"
                    wire:loading.attr="disabled"
                />
            @endif

            <button
                type="button"
                wire:click="reprintLastLabel"
                wire:loading.attr="disabled"
                class="fi-btn fi-color-custom fi-btn-color-gray fi-color-gray fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-white text-gray-950 hover:bg-gray-50 focus-visible:ring-primary-600/20 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20 disabled:opacity-50 disabled:pointer-events-none"
            >
                <x-filament::icon icon="heroicon-o-printer" class="fi-btn-icon h-5 w-5" />
                <span>Reprint</span>
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
