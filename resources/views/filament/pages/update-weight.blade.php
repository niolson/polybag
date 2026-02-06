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

    @script
    <script>
        let scaleDevice = null;

        // Get scale IDs from localStorage
        function getScaleIds() {
            const vendorId = localStorage.getItem('scaleVendorId');
            const productId = localStorage.getItem('scaleProductId');

            return {
                vendorId: vendorId ? (vendorId.startsWith('0x') ? parseInt(vendorId, 16) : parseInt(vendorId)) : null,
                productId: productId ? (productId.startsWith('0x') ? parseInt(productId, 16) : parseInt(productId)) : null
            };
        }

        Alpine.data('scaleData', () => ({
            scaleConnected: false,
        }));

        function getScaleFilters() {
            const { vendorId, productId } = getScaleIds();
            if (vendorId && productId) {
                return [{ vendorId, productId }];
            }
            return [];
        }

        async function connectScale() {
            try {
                const devices = await navigator.hid.requestDevice({
                    filters: getScaleFilters()
                });

                if (devices.length > 0) {
                    scaleDevice = devices[0];
                    await scaleDevice.open();

                    Alpine.store('scaleConnected', true);

                    scaleDevice.addEventListener('inputreport', (event) => {
                        const weight = parseScaleData(event.data);
                        if (weight !== null && weight > 0) {
                            $wire.data.weight = weight.toFixed(2);
                        }
                    });

                }
            } catch (error) {
                console.error('Failed to connect to scale:', error);
            }
        }

        function parseScaleData(data) {
            const dataView = new DataView(data.buffer);

            if (data.byteLength >= 6) {
                const reportId = dataView.getUint8(0);
                const status = dataView.getUint8(1);
                const unit = dataView.getUint8(2);
                const scalingFactor = dataView.getInt8(3);
                const weightLSB = dataView.getUint8(4);
                const weightMSB = dataView.getUint8(5);

                let weight = (weightMSB << 8) | weightLSB;
                weight = weight * Math.pow(10, scalingFactor);

                // Convert to pounds if in grams
                if (unit === 2) { // grams
                    weight = weight / 453.592;
                } else if (unit === 11) { // ounces
                    weight = weight / 16;
                }

                // Status: 4 = stable, 5 = under zero, 6 = over capacity
                if (status === 4) {
                    return weight;
                }
            }

            return null;
        }

        // Auto-connect to previously paired scale
        if (navigator.hid) {
            navigator.hid.getDevices().then(devices => {
                const { vendorId, productId } = getScaleIds();
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

                        scaleDevice.addEventListener('inputreport', (event) => {
                            const weight = parseScaleData(event.data);
                            if (weight !== null && weight > 0) {
                                $wire.data.weight = weight.toFixed(2);
                            }
                        });
                        // Scale auto-connected
                    });
                }
            });
        }
    </script>
    @endscript
</x-filament-panels::page>
