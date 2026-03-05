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
                    <div class="text-center py-8">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-scale class="w-7 h-7 text-gray-400" />
                        </div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">No Recent Updates</h4>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Weight updates will appear here.</p>
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
    <x-qz-tray />
    <x-scale-script />

    @script
    <script>
        Alpine.data('scaleData', () => ({
            scaleConnected: false,
        }));

        async function connectScale() {
            try {
                const deviceInfo = ScaleUtils.getScaleDeviceInfo();
                if (!deviceInfo) {
                    console.warn('No scale configured in Device Settings.');
                    return;
                }

                await ScaleUtils.claimScale();
                await ScaleUtils.startScaleStream((result) => {
                    if (result.isStable && result.weight > 0) {
                        $wire.data.weight = result.weight.toFixed(2);
                    }
                });
                Alpine.store('scaleConnected', true);
            } catch (error) {
                console.error('Failed to connect to scale:', error);
            }
        }

        // Auto-connect scale: WebHID can connect immediately, QZ Tray must wait
        if (ScaleUtils.backend === 'webhid') {
            const deviceInfo = ScaleUtils.getScaleDeviceInfo();
            if (deviceInfo) {
                connectScale();
            }
        } else {
            document.addEventListener('qz-tray:connected', () => {
                const deviceInfo = ScaleUtils.getScaleDeviceInfo();
                if (deviceInfo) {
                    connectScale();
                }
            });
        }
    </script>
    @endscript
</x-filament-panels::page>
