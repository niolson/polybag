<x-filament-panels::page>
    <x-qz-tray />

    @if($package)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
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
                        <div>{{ $package->shipment->city }}, {{ $package->shipment->state }} {{ $package->shipment->zip }}</div>
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
                    @else
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
                        <form wire:submit="ship">
                            {{ $this->form }}
                        </form>
                    @endif
                </x-filament::section>
            </div>
        </div>
    @else
        <div class="text-center py-12">
            <x-filament::icon icon="heroicon-o-cube" class="w-16 h-16 mx-auto text-gray-400" />
            <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">No Package Selected</h3>
            <p class="mt-2 text-gray-500 dark:text-gray-400">Select a package from the packing page to ship.</p>
            <x-filament::button
                href="/pack"
                tag="a"
                class="mt-4"
            >
                Go to Packing
            </x-filament::button>
        </div>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
