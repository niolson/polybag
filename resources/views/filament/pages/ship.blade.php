<x-filament-panels::page>
    <x-qz-tray />

    @if($package)
        <div
            x-data="{
                _scanBuffer: '',
                _scanTimeout: null,

                init() {
                    $wire.set('labelFormat', localStorage.getItem('labelFormat') || 'pdf');
                    $wire.set('labelDpi', parseInt(localStorage.getItem('labelDpi') || '203') || null);
                },

                handleGlobalKey(e) {
                    const el = document.activeElement;
                    const tag = el?.tagName ?? '';
                    const type = (el?.type ?? '').toLowerCase();
                    const isTextInput = (tag === 'INPUT' && !['radio', 'checkbox', 'button', 'submit', 'reset'].includes(type))
                        || tag === 'TEXTAREA' || tag === 'SELECT';
                    if (isTextInput) return;

                    if (e.key === 'Enter') {
                        const trimmed = this._scanBuffer.trim();
                        this._scanBuffer = '';
                        clearTimeout(this._scanTimeout);
                        if (trimmed.startsWith('*')) {
                            this.executeCommand(trimmed.substring(1));
                        }
                        return;
                    }

                    if (e.key.length === 1) {
                        this._scanBuffer += e.key;
                        clearTimeout(this._scanTimeout);
                        this._scanTimeout = setTimeout(() => { this._scanBuffer = ''; }, 1000);
                    }
                },

                executeCommand(code) {
                    if (code.toUpperCase() === '1') {
                        $wire.ship();
                    }
                },
            }"
            @keydown.window="handleGlobalKey($event)"
            class="grid grid-cols-1 lg:grid-cols-2 gap-6"
        >
            <!-- Package Info -->
            <div class="space-y-6">
                <x-filament::section>
                    <x-slot name="heading">Package Details</x-slot>

                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="font-medium text-gray-500 dark:text-gray-400">Weight</dt>
                            <dd class="mt-1">{{ $package->weight }} lbs</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500 dark:text-gray-400">Dimensions</dt>
                            <dd class="mt-1">{{ $package->length }}" x {{ $package->width }}" x {{ $package->height }}"</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500 dark:text-gray-400">Items</dt>
                            <dd class="mt-1">{{ $package->packageItems->count() }} items</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500 dark:text-gray-400">Box</dt>
                            <dd class="mt-1">{{ $package->boxSize?->name ?? 'Custom' }}</dd>
                        </div>
                        @if($package->shipment->location && app(\App\Services\SettingsService::class)->get('multi_location_enabled', false))
                            <div>
                                <dt class="font-medium text-gray-500 dark:text-gray-400">Ship from</dt>
                                <dd class="mt-1">{{ $package->shipment->location->name }}</dd>
                            </div>
                        @endif
                        @if($deliverByDate)
                            <div>
                                <dt class="font-medium text-gray-500 dark:text-gray-400">Deliver by</dt>
                                <dd class="mt-1 font-semibold text-primary-600 dark:text-primary-400">{{ $deliverByDate }}</dd>
                            </div>
                        @endif
                    </dl>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">Ship To</x-slot>

                    <address class="not-italic text-sm">
                        <div class="font-medium">{{ $package->shipment->first_name }} {{ $package->shipment->last_name }}</div>
                        @if($package->shipment->company)
                            <div>{{ $package->shipment->company }}</div>
                        @endif
                        <div>{{ $package->shipment->address1 }}</div>
                        @if($package->shipment->address2)
                            <div>{{ $package->shipment->address2 }}</div>
                        @endif
                        <div>{{ $package->shipment->city }}, {{ $package->shipment->state_or_province }} {{ $package->shipment->postal_code }}</div>
                    </address>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">Items in Package</x-slot>

                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($package->packageItems as $item)
                            <li class="py-2 flex justify-between">
                                <span>{{ $item->product?->name ?? $item->shipmentItem?->description ?? $item->barcode }}</span>
                                <span class="text-gray-500">x{{ $item->quantity }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            </div>

            <!-- Rate Selection -->
            <div>
                @if($this->isShipped())
                    {{-- Reached only when the label was bought but could not be
                         printed: the print handler keeps the page open so the
                         error banner is seen. Nothing here may buy again. --}}
                    <x-filament::section>
                        <x-slot name="heading">Label Purchased</x-slot>
                        <div class="flex items-start gap-3">
                            <x-filament::icon icon="heroicon-o-check-circle" class="w-6 h-6 text-success-500 flex-shrink-0" />
                            <div class="text-sm text-gray-700 dark:text-gray-300 space-y-1">
                                <p class="font-medium text-gray-900 dark:text-white">
                                    {{ $package->carrier }} {{ $package->service }}
                                    @if($package->cost !== null) — ${{ number_format((float) $package->cost, 2) }} @endif
                                </p>
                                @if($package->tracking_number)
                                    <p>Tracking <span class="font-mono">{{ $package->tracking_number }}</span></p>
                                @endif
                                <p class="text-gray-500 dark:text-gray-400">
                                    The label is bought and stored on the package. If it did not print, fix the printer in Device Settings and use <strong>Print again</strong>, or reprint it later from the Packages page. Buying another label for this package is not possible here.
                                </p>
                            </div>
                        </div>
                    </x-filament::section>
                @else
                <x-filament::section>
                    <x-slot name="heading">Select Shipping Rate</x-slot>

                    @if(empty($rateOptions))
                        <div class="text-center py-8">
                            <x-filament::icon
                                icon="heroicon-o-exclamation-triangle"
                                class="w-12 h-12 mx-auto text-warning-500"
                            />
                            <p class="mt-2 text-gray-500 dark:text-gray-400">No shipping rates available for this package.</p>
                            <p class="text-sm text-gray-400 dark:text-gray-500">Check the shipping method configuration.</p>
                        </div>
                    @endif
                    @if(!empty($rateOptions))
                        @if($allRatesLate && $deliverByDate)
                            <div class="rounded-lg bg-warning-50 dark:bg-warning-950 p-4 mb-4 border border-warning-300 dark:border-warning-700">
                                <div class="flex items-center gap-2">
                                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5 text-warning-600 dark:text-warning-400" />
                                    <p class="text-sm font-medium text-warning-800 dark:text-warning-200">
                                        No options meet the deliver-by date of {{ $deliverByDate }}
                                    </p>
                                </div>
                            </div>
                        @endif
                        {{-- Selection is read from the server state rather than mirrored
                             in Alpine: a blind purchase below can take the selection away
                             from this list, and two copies of "what is selected" would
                             disagree the moment it does. --}}
                        <div class="space-y-2" x-data="{ get selected() { return $wire.selectedRateIndex === null ? null : Number($wire.selectedRateIndex) } }">
                            @foreach($rateOptions as $index => $rate)
                                @php
                                    $logoFile = match(strtolower($rate['carrier'] ?? '')) {
                                        'usps' => 'usps-logo.svg',
                                        'fedex' => 'fedex-logo.svg',
                                        'ups' => 'ups-logo.svg',
                                        default => null,
                                    };
                                @endphp
                                <label
                                    wire:key="rate-{{ $index }}"
                                    x-bind:class="selected === {{ $index }}
                                        ? 'border-primary-500 bg-primary-50 dark:bg-primary-950 dark:border-primary-500'
                                        : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'"
                                    class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                                >
                                    <input
                                        type="radio"
                                        wire:model.live="selectedRateIndex"
                                        value="{{ $index }}"
                                        class="text-primary-600 focus:ring-primary-500"
                                    >
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 font-medium text-sm text-gray-900 dark:text-white">
                                            @if($logoFile)
                                                <img src="{{ asset('images/' . $logoFile) }}" class="h-5 w-auto flex-shrink-0" alt="{{ $rate['carrier'] }}">
                                            @else
                                                <span>{{ $rate['carrier'] }}</span>
                                            @endif
                                            <span>{{ $rate['serviceName'] }}</span>
                                            @php
                                                $resoldVia = match($rate['observedService']['source'] ?? null) {
                                                    'amazon' => 'via Amazon',
                                                    'shopify' => 'via Shopify',
                                                    default => null,
                                                };
                                            @endphp
                                            @if($resoldVia)
                                                {{-- The carrier shown is the one carrying the parcel; the
                                                     postage is bought from the channel, not on our account. --}}
                                                <x-filament::badge color="gray" size="sm">{{ $resoldVia }}</x-filament::badge>
                                            @endif
                                        </div>
                                        @if(!empty($formRateOptionDescriptions[$index]))
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $formRateOptionDescriptions[$index] }}</div>
                                        @endif
                                        @if(!empty($rate['specialServices']['applied']) || !empty($rate['specialServices']['stripped']))
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                @foreach($rate['specialServices']['applied'] ?? [] as $serviceName)
                                                    <x-filament::badge color="success" size="sm">{{ $serviceName }}</x-filament::badge>
                                                @endforeach
                                                @foreach($rate['specialServices']['stripped'] ?? [] as $serviceName)
                                                    <span title="Not available for this carrier service — the label will be purchased without it">
                                                        <x-filament::badge color="gray" size="sm"><s>{{ $serviceName }}</s></x-filament::badge>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    {{-- Priced rates end here. What follows is not one, and is
                         separated deliberately: Shopify Shipping reports no
                         price and no service, before or after the purchase, so
                         it is never sorted, ranked or compared against the list
                         above (ADR-0003 decisions 5 and 6). --}}
                    @if(!empty($blindPurchaseOffers))
                        <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-4 space-y-2">
                            <div class="rounded-lg bg-warning-50 dark:bg-warning-950 p-3 border border-warning-300 dark:border-warning-700">
                                <div class="flex items-start gap-2">
                                    <x-filament::icon icon="heroicon-o-eye-slash" class="w-5 h-5 flex-shrink-0 text-warning-600 dark:text-warning-400" />
                                    <div class="text-sm text-warning-800 dark:text-warning-200">
                                        <p class="font-medium">Bought without a price or a service</p>
                                        <p class="mt-0.5 text-xs">
                                            These options are not quotes. The carrier, the service and the cost are
                                            chosen by the seller and are not reported back — not before the purchase,
                                            and not after it. Nothing here can be compared with the rates above.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            @foreach($blindPurchaseOffers as $offer)
                                <label
                                    wire:key="blind-{{ $offer['id'] }}"
                                    x-bind:class="$wire.selectedBlindOfferId === '{{ $offer['id'] }}'
                                        ? 'border-warning-500 bg-warning-50 dark:bg-warning-950 dark:border-warning-500'
                                        : 'border-dashed border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500'"
                                    class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                                >
                                    <input
                                        type="radio"
                                        wire:model.live="selectedBlindOfferId"
                                        value="{{ $offer['id'] }}"
                                        class="text-warning-600 focus:ring-warning-500"
                                    >
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 font-medium text-sm text-gray-900 dark:text-white">
                                            <span>{{ $offer['sourceLabel'] }}</span>
                                            <span>{{ $offer['selectionLabel'] }}</span>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            Price and service unknown until purchase — you will be asked to confirm
                                        </div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
                @endif
            </div>
        </div>
    @else
        <div class="text-center py-16">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-950">
                <x-filament::icon icon="heroicon-o-cube" class="w-8 h-8 text-primary-500" />
            </div>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">No Package Selected</h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Select a package from the packing page to ship.</p>
            <x-filament::button
                href="{{ $returnUrl }}"
                tag="a"
                class="mt-4"
            >
                Go Back
            </x-filament::button>
        </div>
    @endif

    <x-filament::modal id="customs-weight-override" width="md">
        <x-slot name="heading">Customs Weight Mismatch</x-slot>
        <x-slot name="description">
            The total item weight exceeds the package scale weight.
            Would you like to adjust the customs item weights to match the package weight?
        </x-slot>
        <x-slot name="footerActions">
            <x-filament::button wire:click="confirmCustomsWeightOverride">
                Override & Ship
            </x-filament::button>
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'customs-weight-override' })">
                Cancel
            </x-filament::button>
        </x-slot>
    </x-filament::modal>

    <x-filament::modal id="declared-weight-override" width="md">
        <x-slot name="heading">Declared Weight Exceeds Package Weight</x-slot>
        <x-slot name="description">
            {{ $declaredWeightMessage }}
        </x-slot>
        <x-slot name="footerActions">
            <x-filament::button color="warning" wire:click="confirmDeclaredWeightOverride">
                Try anyway at the scale weight
            </x-filament::button>
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'declared-weight-override' })">
                Cancel
            </x-filament::button>
        </x-slot>
    </x-filament::modal>

    <x-filament::modal id="blind-purchase-confirm" width="md">
        <x-slot name="heading">Buy without a price or a service?</x-slot>
        <x-slot name="description">
            This label is bought on the seller's account. They choose the carrier and the service
            themselves, and report neither back — so the cost will not appear against this package,
            the service will stay unknown, and the label cannot be voided from PolyBag.
        </x-slot>
        <x-slot name="footerActions">
            <x-filament::button color="warning" wire:click="confirmBlindPurchase">
                I understand — buy the label
            </x-filament::button>
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'blind-purchase-confirm' })">
                Cancel
            </x-filament::button>
        </x-slot>
    </x-filament::modal>

    <x-filament-actions::modals />

    <x-legal-disclaimers :show="['fedex']" />
</x-filament-panels::page>
