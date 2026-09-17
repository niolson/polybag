@php
    $isAdmin = auth()->user()->role->isAtLeast(\App\Enums\Role::Admin);
@endphp

<x-filament-panels::page>
    <x-qz-tray />
    <x-scale-script />

    @if($shipment?->location && app(\App\Services\SettingsService::class)->get('multi_location_enabled', false))
        <div class="rounded-lg border border-primary-300 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-800 dark:border-primary-700 dark:bg-primary-950 dark:text-primary-200">
            Packing from {{ $shipment->location->name }}
        </div>
    @endif

    <div
        x-data="{
            scaleConnected: false,
            scaleStable: true,
            autoShipEnabled: false,
            labelFormat: PrinterSettings.labelFormat(),
            labelDpi: PrinterSettings.labelDpi(),
            hasReportPrinter: PrinterSettings.hasDocumentPrinter(),
            packingItems: @js($packingItems),
            transparencyEnabled: @js($transparencyEnabled),
            scanToAddMode: @js($scanToAddMode),
            packingValidationEnabled: @js($packingValidationEnabled),
            boxSizes: @js($boxSizes),
            boxSizeId: null,
            weight: '',
            height: '',
            width: '',
            length: '',
            lastScaleWeight: null,
            input: '',
            hasShipment: {{ $shipment ? 'true' : 'false' }},
            pendingTransparencyKey: null,
            transparencyInput: '',
            showTransparencyModal: false,
            isShipping: false,

            init() {
                const phpAutoShip = @js($autoShipOverride);
                if (phpAutoShip !== null) {
                    this.autoShipEnabled = phpAutoShip;
                    localStorage.setItem('autoShipEnabled', phpAutoShip ? 'true' : 'false');
                } else {
                    const stored = localStorage.getItem('autoShipEnabled');
                    if (stored !== null) {
                        this.autoShipEnabled = stored === 'true';
                    }
                }

                this.$watch('autoShipEnabled', (value) => {
                    localStorage.setItem('autoShipEnabled', value.toString());
                });

                // Auto-connect scale: WebHID can connect immediately, QZ Tray must wait
                if (ScaleUtils.backend === 'webhid') {
                    this.autoConnectScale();
                } else {
                    document.addEventListener('qz-tray:connected', () => this.autoConnectScale());
                }
            },

            handleScan() {
                if (this.isShipping) return;
                const trimmed = this.input.trim();
                if (!trimmed) return;

                // Check for command barcode first (starts with *)
                if (trimmed.startsWith('*')) {
                    this.executeCommand(trimmed.substring(1));
                    this.input = '';
                    return;
                }

                if (!this.hasShipment) {
                    $wire.navigateToShipment(trimmed);
                    this.input = '';
                    return;
                }

                const isBoxCode = !!this.boxSizes[trimmed];
                const hasDimensions = this.height && this.width && this.length;

                if (this.scanToAddMode) {
                    // In scan-to-add mode, prefer box scan when dimensions aren't set yet;
                    // otherwise always try to look up as a product
                    if (isBoxCode && !hasDimensions) {
                        this.scanBox(trimmed);
                    } else {
                        $wire.addItemByScan(trimmed);
                    }
                } else {
                    const isProduct = this.packingItems.some(item => item.barcode === trimmed || item.sku === trimmed);

                    if (isBoxCode && isProduct) {
                        // Collision: prefer box code if dimensions aren't set, product if they are
                        hasDimensions ? this.scanProduct(trimmed) : this.scanBox(trimmed);
                    } else if (isBoxCode) {
                        this.scanBox(trimmed);
                    } else {
                        this.scanProduct(trimmed);
                    }
                }

                this.input = '';
            },

            executeCommand(code) {
                const commands = {
                    '1': () => this.shipPackage(),
                    '2': () => $wire.reprintLastLabel(),
                    '3': () => $wire.cancelLastLabel(),
                    '0': () => this.clearShipment(),
                };

                const action = commands[code.toUpperCase()];
                if (action) {
                    action();
                } else {
                    new FilamentNotification()
                        .title('Unknown Command')
                        .body(`Command '${code}' not recognized`)
                        .danger()
                        .send();
                }
            },

            clearShipment() {
                if (this.hasShipment) {
                    window.location.href = '/pack';
                }
            },

            scanBox(code) {
                const box = this.boxSizes[code];
                if (box) {
                    this.boxSizeId = box.id;
                    this.height = box.height;
                    this.width = box.width;
                    this.length = box.length;
                } else {
                    new FilamentNotification()
                        .title('Box not found')
                        .body(`Box code '${code}' not found`)
                        .danger()
                        .send();
                }
            },

            scanProduct(barcode) {
                const idx = this.packingItems.findIndex(item => item.barcode === barcode || item.sku === barcode);
                if (idx === -1) {
                    new FilamentNotification()
                        .title('Item not found')
                        .body(`Item '${barcode}' not found in shipment`)
                        .danger()
                        .send();
                    return;
                }

                const item = this.packingItems[idx];

                if (item.packed >= item.quantity) {
                    new FilamentNotification()
                        .title('Already packed')
                        .body('Item already fully packed')
                        .danger()
                        .send();
                    return;
                }

                if (this.transparencyEnabled && item.transparency) {
                    this.pendingTransparencyKey = idx;
                    this.showTransparencyModal = true;
                    this.$nextTick(() => {
                        const input = this.$refs.transparencyInput;
                        if (input) input.focus();
                    });
                } else {
                    this.packingItems[idx].packed++;
                }
            },

            addScannedProduct(product) {
                const existingIdx = this.packingItems.findIndex(item => item.product_id === product.product_id);
                if (existingIdx !== -1) {
                    this.packingItems[existingIdx].packed++;
                    this.packingItems[existingIdx].quantity++;
                } else {
                    this.packingItems.push({
                        product_id: product.product_id,
                        sku: product.sku,
                        barcode: product.barcode,
                        name: product.name,
                        quantity: 1,
                        packed: 1,
                        transparency_codes: [],
                    });
                }
            },

            incrementItem(idx) {
                this.packingItems[idx].packed++;
                this.packingItems[idx].quantity++;
            },

            decrementItem(idx) {
                if (this.packingItems[idx].packed <= 1) {
                    this.packingItems.splice(idx, 1);
                } else {
                    this.packingItems[idx].packed--;
                    this.packingItems[idx].quantity--;
                }
            },

            submitTransparency() {
                if (this.pendingTransparencyKey === null) {
                    this.closeTransparencyModal();
                    return;
                }

                const code = this.transparencyInput.trim();
                if (code.length < 29 || code.length > 38) {
                    new FilamentNotification()
                        .title('Invalid Code')
                        .body('Transparency code must be 29-38 characters')
                        .danger()
                        .send();
                    return;
                }

                const key = this.pendingTransparencyKey;
                this.packingItems[key].packed++;
                if (!this.packingItems[key].transparency_codes) {
                    this.packingItems[key].transparency_codes = [];
                }
                this.packingItems[key].transparency_codes.push(code);

                this.closeTransparencyModal();
            },

            closeTransparencyModal() {
                this.showTransparencyModal = false;
                this.pendingTransparencyKey = null;
                this.transparencyInput = '';
                this.$nextTick(() => {
                    this.$refs.scanInput?.focus();
                });
            },

            isReadyToShip() {
                if (!this.hasShipment) return false;

                const w = parseFloat(this.weight);
                const h = parseFloat(this.height);
                const wi = parseFloat(this.width);
                const d = parseFloat(this.length);

                if (!w || w <= 0 || !h || h <= 0 || !wi || wi <= 0 || !d || d <= 0) {
                    return false;
                }

                if (this.scanToAddMode) {
                    if (this.packingItems.length === 0) return false;
                } else if (this.packingValidationEnabled) {
                    if (this.packingItems.some(item => item.packed < item.quantity)) return false;
                }

                return true;
            },

            async shipPackage() {
                if (this.isShipping || !this.isReadyToShip()) return;
                const shouldKeepLoadingOnSuccess = !this.autoShipEnabled;
                let completed = false;
                this.isShipping = true;

                try {
                    await $wire.ship(
                        this.packingItems,
                        this.boxSizeId,
                        this.weight,
                        this.height,
                        this.width,
                        this.length,
                        this.autoShipEnabled,
                        this.labelFormat,
                        this.labelDpi,
                        this.hasReportPrinter
                    );
                    completed = true;
                } finally {
                    if (!completed || !shouldKeepLoadingOnSuccess) {
                        this.isShipping = false;
                    }
                }
            },

            async autoConnectScale() {
                const deviceInfo = ScaleUtils.getScaleDeviceInfo();
                if (!deviceInfo) return;

                try {
                    await ScaleUtils.claimScale();
                    await ScaleUtils.startScaleStream((result) => {
                        this.scaleStable = result.isStable;
                        const formatted = result.weight.toFixed(2);
                        if (formatted !== this.lastScaleWeight) {
                            this.lastScaleWeight = formatted;
                            this.weight = formatted;
                        }
                    });
                    this.scaleConnected = true;
                } catch (error) {
                    console.error('Failed to auto-connect scale:', error);
                }
            },

            handleGlobalKey(e) {
                if (this.showTransparencyModal) return;
                if (document.activeElement === this.$refs.scanInput) return;

                const el = document.activeElement;
                const tag = el?.tagName ?? '';
                const type = (el?.type ?? '').toLowerCase();
                const isTextInput = (tag === 'INPUT' && !['radio', 'checkbox', 'button', 'submit', 'reset'].includes(type))
                    || tag === 'TEXTAREA' || tag === 'SELECT';
                if (isTextInput) return;

                if (e.ctrlKey || e.altKey || e.metaKey) return;
                if (e.key.length !== 1) return;

                e.preventDefault();
                this.input += e.key;
                this.$refs.scanInput?.focus();
            },
        }"
        @focus-scan-input.window="$nextTick(() => { input = ''; $refs.scanInput.focus(); })"
        @keydown.window="handleGlobalKey($event)"
        @keydown.f12.window.prevent="shipPackage()"
        @scan-to-add-found.window="addScannedProduct($event.detail.product)"
        @scan-to-add-not-found.window="new FilamentNotification().title('Product not found').body(`No product found for '${$event.detail.barcode}'`).danger().send()"
        @shipping-error.window="isShipping = false"
    >
        {{-- Header buttons --}}
        <div class="flex items-center justify-between gap-3 mb-4">
            <a href="/print-command-barcodes" target="_blank" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <x-filament::icon icon="heroicon-o-printer" class="w-4 h-4 inline-block mr-1" />
                Print Command Barcodes
            </a>
            <div class="flex items-center gap-3">
            @if($isAdmin)
            <x-shipping-auto-ship-toggle
                x-on:click="autoShipEnabled = !autoShipEnabled"
                ::disabled="isShipping"
            />
            @endif

            <x-shipping-submit-button
                type="button"
                x-on:click="shipPackage()"
                ::disabled="isShipping || !isReadyToShip()"
                loading-type="alpine"
                loading-variable="isShipping"
            />
            </div>
        </div>

        <form @submit.prevent="handleScan()">
            <div class="flex gap-4 mb-4">
                <div class="flex-1">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            x-ref="scanInput"
                            type="text"
                            x-model="input"
                            x-bind:placeholder="hasShipment ? 'Scan product barcode or box code' : 'Scan Shipment ID'"
                            x-bind:disabled="isShipping"
                            autofocus
                        />
                    </x-filament::input.wrapper>
                </div>
            </div>

            <x-filament::fieldset>
                <x-slot name="label">
                    Package Measurements
                </x-slot>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">
                            Weight (lbs)
                            <span x-show="!scaleConnected" x-cloak class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                (No scale connected)
                            </span>
                            <span x-show="scaleConnected && !scaleStable" x-cloak class="text-xs font-normal text-warning-500">
                                In motion...
                            </span>
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="number"
                                step="0.01"
                                x-model="weight"
                                x-bind:disabled="isShipping"
                                x-bind:style="scaleConnected && !scaleStable ? 'color: var(--warning-500)' : ''"
                            />
                        </x-filament::input.wrapper>
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">
                            Height (in)
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="number"
                                step="0.01"
                                x-model="height"
                                x-bind:disabled="isShipping"
                            />
                        </x-filament::input.wrapper>
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">
                            Width (in)
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="number"
                                step="0.01"
                                x-model="width"
                                x-bind:disabled="isShipping"
                            />
                        </x-filament::input.wrapper>
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">
                            Length (in)
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="number"
                                step="0.01"
                                x-model="length"
                                x-bind:disabled="isShipping"
                            />
                        </x-filament::input.wrapper>
                    </div>
                </div>
            </x-filament::fieldset>

            <div x-show="isShipping" x-cloak class="flex items-center gap-2 mt-3 text-sm text-primary-600 dark:text-primary-400">
                <x-filament::loading-indicator class="h-4 w-4" />
                <span>Contacting carrier, please wait...</span>
            </div>

            <button type="submit" hidden>Submit</button>
        </form>

    @if($shipment)
    <x-filament::section class="mt-6 col-span-full" :has-content-el="false">
        <x-slot name="heading">
            Shipment: {{ $shipment->shipment_reference }}
        </x-slot>
        <x-slot name="description">
            @if($multiClientEnabled && $clientName)
                <x-filament::badge color="primary" class="mr-2">{{ $clientName }}</x-filament::badge>
            @endif
            @if($scanToAddMode)
                <x-filament::badge color="warning" class="mr-2">Scan-to-Add</x-filament::badge>
            @endif
            {{ $shipment->first_name }} {{ $shipment->last_name }} - {{ $shipment->city }}, {{ $shipment->state_or_province }}
        </x-slot>

        <div class="w-full overflow-x-auto">
            {{-- Scan-to-add table: accumulated count with ± controls --}}
            <template x-if="scanToAddMode">
                <table class="fi-ta-table w-full">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="px-3 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white w-32">Qty Packed</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-950 dark:text-white">SKU</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-950 dark:text-white">Barcode</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-950 dark:text-white">Name</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        <template x-if="packingItems.length === 0">
                            <tr>
                                <td colspan="4" class="px-3 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Scan a product barcode to begin recording what's packed
                                </td>
                            </tr>
                        </template>
                        <template x-for="(packingItem, index) in packingItems" :key="packingItem.product_id">
                            <tr>
                                <td class="px-3 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button
                                            type="button"
                                            @click="decrementItem(index)"
                                            class="flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                                        >
                                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
                                            </svg>
                                        </button>
                                        <span class="min-w-[1.5rem] text-center text-sm font-semibold text-gray-950 dark:text-white" x-text="packingItem.packed"></span>
                                        <button
                                            type="button"
                                            @click="incrementItem(index)"
                                            class="flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                                        >
                                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-sm text-gray-950 dark:text-white" x-text="packingItem.sku"></td>
                                <td class="px-3 py-4 text-sm text-gray-950 dark:text-white" x-text="packingItem.barcode"></td>
                                <td class="px-3 py-4 text-sm text-gray-950 dark:text-white max-w-xs truncate" x-text="packingItem.name" x-bind:title="packingItem.name"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </template>

            {{-- Validate-mode table: expected vs packed with status badges --}}
            <template x-if="!scanToAddMode">
                <table class="fi-ta-table w-full">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="px-3 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white">Qty</th>
                            <th class="px-3 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white">Packed</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-950 dark:text-white">SKU</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-950 dark:text-white">Barcode</th>
                            <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-950 dark:text-white">Name</th>
                            @if($transparencyEnabled)
                            <th class="px-3 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white">Transparency</th>
                            @endif
                            <th class="px-3 py-3.5 text-center text-sm font-semibold text-gray-950 dark:text-white">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        <template x-for="(packingItem, index) in packingItems" :key="packingItem.id">
                            <tr>
                                <td class="px-3 py-4 text-center text-sm text-gray-950 dark:text-white" x-text="packingItem.quantity"></td>
                                <td class="px-3 py-4 text-center text-sm text-gray-950 dark:text-white" x-text="packingItem.packed"></td>
                                <td class="px-3 py-4 text-sm text-gray-950 dark:text-white" x-text="packingItem.sku"></td>
                                <td class="px-3 py-4 text-sm text-gray-950 dark:text-white" x-text="packingItem.barcode"></td>
                                <td class="px-3 py-4 text-sm text-gray-950 dark:text-white max-w-xs truncate" x-text="packingItem.name" x-bind:title="packingItem.name"></td>
                                @if($transparencyEnabled)
                                <td class="px-3 py-4 text-center">
                                    <template x-if="packingItem.transparency">
                                        <template x-if="(packingItem.transparency_codes || []).length >= packingItem.quantity">
                                            <svg class="h-5 w-5 text-success-500 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </template>
                                    </template>
                                    <template x-if="packingItem.transparency && (packingItem.transparency_codes || []).length < packingItem.quantity">
                                        <svg class="h-5 w-5 text-warning-500 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </template>
                                </td>
                                @endif
                                <td class="px-3 py-4 text-center">
                                    <template x-if="packingItem.packed < packingItem.quantity">
                                        <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-w-[theme(spacing.6)] py-1 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30 fi-color-warning" style="--c-50:var(--warning-50);--c-400:var(--warning-400);--c-600:var(--warning-600);">
                                            <svg class="fi-badge-icon h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>Not Packed</span>
                                        </span>
                                    </template>
                                    <template x-if="packingItem.packed == packingItem.quantity">
                                        <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-w-[theme(spacing.6)] py-1 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30 fi-color-success" style="--c-50:var(--success-50);--c-400:var(--success-400);--c-600:var(--success-600);">
                                            <svg class="fi-badge-icon h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>Packed</span>
                                        </span>
                                    </template>
                                    <template x-if="packingItem.packed > packingItem.quantity">
                                        <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-w-[theme(spacing.6)] py-1 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30 fi-color-danger" style="--c-50:var(--danger-50);--c-400:var(--danger-400);--c-600:var(--danger-600);">
                                            <svg class="fi-badge-icon h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>Over Packed</span>
                                        </span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </template>
        </div>
    </x-filament::section>
    @else
    <div class="flex items-center justify-center p-16">
        <div class="text-center max-w-sm">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-950">
                <x-filament::icon
                    icon="heroicon-o-archive-box-arrow-down"
                    class="h-8 w-8 text-primary-500"
                />
            </div>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">No Shipment Selected</h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Scan a shipment reference in the field above to begin packing.
            </p>
        </div>
    </div>
    @endif

    @if($transparencyEnabled)
    {{-- Transparency modal - pure Alpine, no server requests --}}
    <div
        x-show="showTransparencyModal"
        x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-40 flex items-center justify-center bg-gray-950/50 dark:bg-gray-950/75"
        @keydown.escape.window="if (showTransparencyModal) closeTransparencyModal()"
    >
        <div
            x-show="showTransparencyModal"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            @click.away="closeTransparencyModal()"
        >
            <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                Scan Transparency Code
            </h2>

            <form @submit.prevent="submitTransparency()" class="mt-4">
                <x-filament::input.wrapper class="mb-4">
                    <x-filament::input
                        x-ref="transparencyInput"
                        type="text"
                        x-model="transparencyInput"
                        placeholder="Scan transparency code..."
                    />
                </x-filament::input.wrapper>
                <x-filament::button type="submit">
                    Submit
                </x-filament::button>
            </form>
        </div>
    </div>
    @endif
    </div>
</x-filament-panels::page>
