<x-filament-panels::page>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Form Section -->
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Update Product Weight</x-slot>
                <x-slot name="description">Scan a product barcode and weigh it to update the stored weight.</x-slot>

                <form wire:submit="update" class="space-y-4">
                    {{ $this->form }}

                    <div class="flex items-center gap-4">
                        <x-filament::button type="submit">
                            Update Weight
                        </x-filament::button>

                        <x-filament::button
                            type="button"
                            color="gray"
                            x-on:click="connectScale()"
                            x-show="!scaleConnected"
                        >
                            <x-slot name="icon">
                                <x-heroicon-o-link class="w-5 h-5" />
                            </x-slot>
                            Connect Scale
                        </x-filament::button>

                        <div x-show="scaleConnected" class="flex items-center gap-2 text-success-600">
                            <x-heroicon-o-check-circle class="w-5 h-5" />
                            <span class="text-sm font-medium">Scale Connected</span>
                        </div>
                    </div>
                </form>
            </x-filament::section>

            @if($currentProduct)
                <x-filament::section>
                    <x-slot name="heading">Current Product</x-slot>

                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="font-medium text-gray-500 dark:text-gray-400">SKU</dt>
                            <dd class="mt-1">{{ $currentProduct->sku }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500 dark:text-gray-400">Name</dt>
                            <dd class="mt-1">{{ $currentProduct->name }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-500 dark:text-gray-400">Current Weight</dt>
                            <dd class="mt-1">{{ $currentProduct->weight ?? 'Not set' }} lbs</dd>
                        </div>
                        @if($currentProduct->upc)
                            <div>
                                <dt class="font-medium text-gray-500 dark:text-gray-400">UPC</dt>
                                <dd class="mt-1">{{ $currentProduct->upc }}</dd>
                            </div>
                        @endif
                    </dl>
                </x-filament::section>
            @endif
        </div>

        <!-- Recent Updates -->
        <div>
            <x-filament::section>
                <x-slot name="heading">Recent Updates</x-slot>

                @if(empty($recentUpdates))
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <x-heroicon-o-scale class="w-12 h-12 mx-auto mb-2 opacity-50" />
                        <p>No recent weight updates.</p>
                    </div>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($recentUpdates as $update)
                            <li class="py-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-medium">{{ $update['sku'] }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $update['name'] }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm">
                                            <span class="text-gray-400">{{ $update['old_weight'] ?? '?' }}</span>
                                            <span class="mx-1">→</span>
                                            <span class="font-medium text-success-600">{{ $update['new_weight'] }}</span>
                                            <span class="text-gray-400">lbs</span>
                                        </p>
                                        <p class="text-xs text-gray-400">{{ $update['updated_at'] }}</p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    </div>

    <x-filament-actions::modals />
    <x-scale-script />

    @script
    <script>
        let scaleDevice = null;

        Alpine.data('scaleData', () => ({
            scaleConnected: false,
        }));

        function handleScaleInput(event) {
            const result = ScaleUtils.parseScaleData(event.data);
            if (result !== null && result.isStable && result.weight > 0) {
                $wire.data.weight = result.weight.toFixed(2);
            }
        }

        async function connectScale() {
            try {
                const devices = await navigator.hid.requestDevice({
                    filters: ScaleUtils.getScaleFilters()
                });

                if (devices.length > 0) {
                    scaleDevice = devices[0];
                    await scaleDevice.open();
                    Alpine.store('scaleConnected', true);
                    scaleDevice.addEventListener('inputreport', handleScaleInput);
                }
            } catch (error) {
                console.error('Failed to connect to scale:', error);
            }
        }

        // Auto-connect to previously paired scale
        if (navigator.hid) {
            navigator.hid.getDevices().then(devices => {
                const { vendorId, productId } = ScaleUtils.getScaleIds();
                const matchedDevice = devices.find(device => {
                    if (vendorId && productId) {
                        return device.vendorId === vendorId && device.productId === productId;
                    }
                    return true;
                });

                if (matchedDevice) {
                    scaleDevice = matchedDevice;
                    scaleDevice.open().then(() => {
                        Alpine.store('scaleConnected', true);
                        scaleDevice.addEventListener('inputreport', handleScaleInput);
                    });
                }
            });
        }
    </script>
    @endscript
</x-filament-panels::page>
