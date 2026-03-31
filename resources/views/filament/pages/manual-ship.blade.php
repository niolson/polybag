@php
    $isAdmin = auth()->user()->role->isAtLeast(\App\Enums\Role::Admin);
    $shippingMethods = $this->getShippingMethodOptions();
@endphp

<x-filament-panels::page>
    <x-qz-tray />
    <x-scale-script />

    <div
        x-data="{
            scaleConnected: false,
            scaleStable: true,
            autoShipEnabled: false,
            labelFormat: localStorage.getItem('labelFormat') || 'pdf',
            labelDpi: parseInt(localStorage.getItem('labelDpi') || '203') || null,
            boxSizes: @js($boxSizes),
            boxSizeId: null,
            weight: '',
            height: '',
            width: '',
            length: '',
            lastScaleWeight: null,
            isShipping: false,

            shipmentReference: '',
            firstName: '',
            lastName: '',
            company: '',
            address1: '',
            address2: '',
            city: '',
            stateOrProvince: '',
            postalCode: '',
            country: 'US',
            countrySearch: '',
            countryOptions: @js($countryOptions),
            subdivisionOptionsByCountry: @js($subdivisionOptionsByCountry),
            administrativeAreaLabels: @js($administrativeAreaLabels),
            phone: '',
            email: '',
            shippingMethodId: null,

            boxInput: '',

            init() {
                this.country = this.countryOptions['US'] ? 'US' : Object.keys(this.countryOptions)[0] || '';

                const stored = localStorage.getItem('manualShipAutoShip');
                if (stored !== null) {
                    this.autoShipEnabled = stored === 'true';
                }

                this.$watch('autoShipEnabled', (value) => {
                    localStorage.setItem('manualShipAutoShip', value.toString());
                });

                if (ScaleUtils.backend === 'webhid') {
                    this.autoConnectScale();
                } else {
                    document.addEventListener('qz-tray:connected', () => this.autoConnectScale());
                }
            },

            filteredCountryOptions() {
                const search = this.countrySearch.trim().toLowerCase();

                if (!search) {
                    return this.countryOptions;
                }

                return Object.fromEntries(
                    Object.entries(this.countryOptions).filter(([code, label]) =>
                        code.toLowerCase().includes(search) || label.toLowerCase().includes(search)
                    )
                );
            },

            scanBox() {
                const trimmed = this.boxInput.trim();
                if (!trimmed) return;

                const box = this.boxSizes[trimmed];
                if (box) {
                    this.boxSizeId = box.id;
                    this.height = box.height;
                    this.width = box.width;
                    this.length = box.length;
                } else {
                    new FilamentNotification()
                        .title('Box not found')
                        .body(`Box code '${trimmed}' not found`)
                        .danger()
                        .send();
                }
                this.boxInput = '';
            },

            selectBoxSize(id) {
                if (!id) {
                    this.boxSizeId = null;
                    return;
                }
                const box = Object.values(this.boxSizes).find(b => b.id == id);
                if (box) {
                    this.boxSizeId = box.id;
                    this.height = box.height;
                    this.width = box.width;
                    this.length = box.length;
                }
            },

            getSubdivisionOptions() {
                return this.subdivisionOptionsByCountry[this.country] || {};
            },

            hasSubdivisionOptions() {
                return Object.keys(this.getSubdivisionOptions()).length > 0;
            },

            getAdministrativeAreaLabel() {
                return this.administrativeAreaLabels[this.country] || 'State / Province';
            },

            onCountryChange() {
                if (this.hasSubdivisionOptions() && !Object.prototype.hasOwnProperty.call(this.getSubdivisionOptions(), this.stateOrProvince)) {
                    this.stateOrProvince = '';
                }
            },

            isReadyToShip() {
                if (!this.address1 || !this.city || !this.country) return false;
                if (!this.firstName && !this.lastName && !this.company) return false;
                if (this.hasSubdivisionOptions() && !this.stateOrProvince) return false;

                const w = parseFloat(this.weight);
                const h = parseFloat(this.height);
                const wi = parseFloat(this.width);
                const d = parseFloat(this.length);

                return w > 0 && h > 0 && wi > 0 && d > 0;
            },

            async shipPackage() {
                if (this.isShipping || !this.isReadyToShip()) return;
                this.isShipping = true;

                try {
                    await $wire.ship(
                        this.firstName,
                        this.lastName,
                        this.company,
                        this.address1,
                        this.address2,
                        this.city,
                        this.stateOrProvince,
                        this.postalCode,
                        this.country,
                        this.phone,
                        this.email,
                        this.shippingMethodId ? parseInt(this.shippingMethodId) : null,
                        this.boxSizeId,
                        this.weight,
                        this.height,
                        this.width,
                        this.length,
                        this.autoShipEnabled,
                        this.shipmentReference,
                        this.labelFormat,
                        this.labelDpi
                    );
                } finally {
                    this.isShipping = false;
                }
            },

            resetForm() {
                this.shipmentReference = '';
                this.firstName = '';
                this.lastName = '';
                this.company = '';
                this.address1 = '';
                this.address2 = '';
                this.city = '';
                this.stateOrProvince = '';
                this.postalCode = '';
                this.country = 'US';
                this.countrySearch = '';
                this.phone = '';
                this.email = '';
                this.shippingMethodId = null;
                this.boxSizeId = null;
                this.weight = '';
                this.height = '';
                this.width = '';
                this.length = '';
                this.$nextTick(() => this.$refs.firstNameInput?.focus());
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
            }
        }"
        @form-reset.window="resetForm()"
        @keydown.f12.window.prevent="shipPackage()"
    >
        {{-- Header buttons --}}
        <div class="flex items-center justify-end gap-3 mb-4">
            @if($isAdmin)
            <button
                type="button"
                x-on:click="autoShipEnabled = !autoShipEnabled"
                :disabled="isShipping"
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
                x-on:click="$wire.reprintLastLabel()"
                class="fi-btn fi-color-custom fi-btn-color-gray fi-color-gray fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-white text-gray-950 hover:bg-gray-50 focus-visible:ring-primary-600/20 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20"
            >
                <x-filament::icon icon="heroicon-o-printer" class="fi-btn-icon h-5 w-5" />
                <span>Reprint</span>
            </button>

            <button
                type="button"
                x-on:click="shipPackage()"
                :disabled="isShipping || !isReadyToShip()"
                class="fi-btn fi-color-custom fi-btn-color-primary fi-color-primary fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 disabled:opacity-50 disabled:pointer-events-none"
            >
                <template x-if="isShipping">
                    <x-filament::loading-indicator class="h-5 w-5" />
                </template>
                <template x-if="!isShipping">
                    <x-filament::icon
                        icon="heroicon-o-paper-airplane"
                        class="fi-btn-icon h-5 w-5"
                    />
                </template>
                <span x-text="autoShipEnabled ? 'Auto Ship' : 'Ship'"></span>
            </button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Left column: Address --}}
            <x-filament::section>
                <x-slot name="heading">Recipient & Address</x-slot>

                <div class="space-y-4">
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Reference (optional)</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" x-model="shipmentReference" x-bind:disabled="isShipping" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">First Name</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" x-ref="firstNameInput" x-model="firstName" x-bind:disabled="isShipping" autofocus />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Last Name</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" x-model="lastName" x-bind:disabled="isShipping" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Company</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" x-model="company" x-bind:disabled="isShipping" />
                        </x-filament::input.wrapper>
                    </div>

                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Address 1</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" x-model="address1" x-bind:disabled="isShipping" />
                        </x-filament::input.wrapper>
                    </div>

                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Address 2</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" x-model="address2" x-bind:disabled="isShipping" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">City</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" x-model="city" x-bind:disabled="isShipping" />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white" x-text="getAdministrativeAreaLabel()"></label>
                            <template x-if="hasSubdivisionOptions()">
                                <x-filament::input.wrapper>
                                    <select
                                        x-model="stateOrProvince"
                                        x-bind:disabled="isShipping"
                                        class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 dark:text-white sm:text-sm sm:leading-6"
                                    >
                                        <option value="">Select option</option>
                                        <template x-for="(label, code) in getSubdivisionOptions()" :key="code">
                                            <option :value="code" x-text="label"></option>
                                        </template>
                                    </select>
                                </x-filament::input.wrapper>
                            </template>
                            <template x-if="!hasSubdivisionOptions()">
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" x-model="stateOrProvince" x-bind:disabled="isShipping" />
                                </x-filament::input.wrapper>
                            </template>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Postal Code</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" x-model="postalCode" x-bind:disabled="isShipping" />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Country</label>
                            <x-filament::input.wrapper class="mb-2">
                                <x-filament::input
                                    type="text"
                                    x-model="countrySearch"
                                    x-bind:disabled="isShipping"
                                    placeholder="Start typing to search"
                                />
                            </x-filament::input.wrapper>
                            <x-filament::input.wrapper>
                                <select
                                    x-model="country"
                                    x-on:change="onCountryChange()"
                                    x-bind:disabled="isShipping"
                                    class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 dark:text-white sm:text-sm sm:leading-6"
                                >
                                    <template x-for="(label, code) in filteredCountryOptions()" :key="code">
                                        <option :value="code" :selected="code === country" x-text="label"></option>
                                    </template>
                                </select>
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Phone</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="tel" x-model="phone" x-bind:disabled="isShipping" />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Email</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="email" x-model="email" x-bind:disabled="isShipping" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Shipping Method</label>
                        <x-filament::input.wrapper>
                            <select
                                x-model="shippingMethodId"
                                x-bind:disabled="isShipping"
                                class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] dark:disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.500)] sm:text-sm sm:leading-6"
                            >
                                <option value="">— None —</option>
                                @foreach($shippingMethods as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </x-filament::input.wrapper>
                    </div>
                </div>
            </x-filament::section>

            {{-- Right column: Package --}}
            <div class="space-y-6">
                <x-filament::section>
                    <x-slot name="heading">Box</x-slot>

                    <div class="space-y-4">
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Scan Box Code</label>
                            <form @submit.prevent="scanBox()">
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" x-model="boxInput" x-bind:disabled="isShipping" placeholder="Scan or type box code" />
                                </x-filament::input.wrapper>
                                <button type="submit" hidden>Submit</button>
                            </form>
                        </div>

                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Or Select Box Size</label>
                            <x-filament::input.wrapper>
                                <select
                                    x-on:change="selectBoxSize($event.target.value)"
                                    x-bind:disabled="isShipping"
                                    class="fi-input block w-full border-none bg-transparent py-1.5 text-base text-gray-950 outline-none transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 dark:text-white sm:text-sm sm:leading-6"
                                >
                                    <option value="">Custom</option>
                                    @php
                                        $boxSizeOptions = \App\Models\BoxSize::orderBy('label')->get();
                                    @endphp
                                    @foreach($boxSizeOptions as $box)
                                        <option value="{{ $box->id }}">{{ $box->label }} ({{ $box->length }}" x {{ $box->width }}" x {{ $box->height }}")</option>
                                    @endforeach
                                </select>
                            </x-filament::input.wrapper>
                        </div>
                    </div>
                </x-filament::section>

                <x-filament::fieldset>
                    <x-slot name="label">Package Measurements</x-slot>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">
                                Weight (lbs)
                                <span x-show="!scaleConnected" x-cloak class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                    (No scale)
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
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Height (in)</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" step="0.01" x-model="height" x-bind:disabled="isShipping" />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Width (in)</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" step="0.01" x-model="width" x-bind:disabled="isShipping" />
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">Length (in)</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" step="0.01" x-model="length" x-bind:disabled="isShipping" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>
                </x-filament::fieldset>

                <div x-show="isShipping" x-cloak class="flex items-center gap-2 text-sm text-primary-600 dark:text-primary-400">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    <span>Contacting carrier, please wait...</span>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
