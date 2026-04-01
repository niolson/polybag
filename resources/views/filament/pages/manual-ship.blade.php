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

                const storedFormat = localStorage.getItem('labelFormat') || 'pdf';
                const storedDpi = parseInt(localStorage.getItem('labelDpi') || '203') || null;

                $wire.set('labelFormat', storedFormat);
                $wire.set('labelDpi', storedDpi);

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
                    await ScaleUtils.startScaleStream((result) => {
                        this.scaleStable = result.isStable;

                        if (result.weight > 0) {
                            $wire.data.weight = result.weight.toFixed(2);
                        }
                    });

                    this.scaleConnected = true;
                } catch (error) {
                    console.error('Failed to auto-connect scale:', error);
                }
            }
        }"
    >
        <div class="sticky top-0 z-10 mb-4 rounded-xl border border-gray-200/70 bg-white/90 p-3 shadow-sm backdrop-blur dark:border-white/10 dark:bg-gray-950/80">
            <div class="flex flex-wrap items-center justify-end gap-3">
            @if($isAdmin)
                <button
                    type="button"
                    x-on:click="autoShipEnabled = !autoShipEnabled"
                    wire:loading.attr="disabled"
                    :class="autoShipEnabled
                        ? 'fi-btn fi-color-custom fi-btn-color-success fi-color-success fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 disabled:opacity-50 disabled:pointer-events-none'
                        : 'fi-btn fi-color-custom fi-btn-color-gray fi-color-gray fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-white text-gray-950 hover:bg-gray-50 focus-visible:ring-primary-600/20 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20 disabled:opacity-50 disabled:pointer-events-none'"
                >
                    <x-filament::icon
                        x-show="autoShipEnabled"
                        icon="heroicon-s-bolt"
                        class="fi-btn-icon h-5 w-5"
                    />
                    <x-filament::icon
                        x-show="!autoShipEnabled"
                        x-cloak
                        icon="heroicon-o-bolt"
                        class="fi-btn-icon h-5 w-5"
                    />
                    <span x-text="autoShipEnabled ? 'Auto Ship: ON' : 'Auto Ship: OFF'"></span>
                </button>
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

            <button
                type="submit"
                form="manual-ship-form"
                wire:loading.attr="disabled"
                class="fi-btn fi-color-custom fi-btn-color-primary fi-color-primary fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 disabled:opacity-50 disabled:pointer-events-none"
            >
                <span wire:loading.remove x-text="autoShipEnabled ? 'Auto Ship' : 'Ship'"></span>
                <span wire:loading>Working...</span>
            </button>
            </div>
        </div>

        <form id="manual-ship-form" wire:submit="ship" class="space-y-6">
            {{ $this->form }}

            <x-filament::section>
                <x-slot name="heading">Scale Status</x-slot>
                <x-slot name="description">Live workstation status for the connected shipping scale.</x-slot>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Connection</div>
                        <div x-show="scaleConnected" x-cloak class="mt-2 text-sm font-semibold text-success-600 dark:text-success-400">
                            Connected
                        </div>
                        <div x-show="!scaleConnected" class="mt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">
                            Not connected
                        </div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Scale State</div>
                        <div x-show="scaleConnected && scaleStable" x-cloak class="mt-2 text-sm font-semibold text-success-600 dark:text-success-400">
                            Stable
                        </div>
                        <div x-show="scaleConnected && !scaleStable" x-cloak class="mt-2 text-sm font-semibold text-warning-600 dark:text-warning-400">
                            In motion
                        </div>
                        <div x-show="!scaleConnected" class="mt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">
                            Waiting
                        </div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Behavior</div>
                        <div class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            Stable readings update the Weight field automatically.
                        </div>
                    </div>
                </div>
            </x-filament::section>
        </form>
    </div>
</x-filament-panels::page>
