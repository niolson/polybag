<x-filament-panels::page>
    <x-qz-tray />

    <div class="space-y-6">
        {{-- Unmanifested Packages --}}
        <x-filament::section>
            <x-slot name="heading">Unmanifested Packages</x-slot>

            @if(empty($unmanifestedByCarrier))
                <div class="text-center py-8">
                    <x-filament::icon
                        icon="heroicon-o-check-circle"
                        class="w-12 h-12 mx-auto text-success-500"
                    />
                    <p class="mt-2 text-gray-500 dark:text-gray-400">All packages have been manifested.</p>
                </div>
            @else
                <div class="space-y-6">
                    @foreach($unmanifestedByCarrier as $carrier => $packages)
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ $carrier }}
                                    <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                                        ({{ count($packages) }} {{ Str::plural('package', count($packages)) }})
                                    </span>
                                </h3>
                                <x-filament::button
                                    wire:click="generateManifest('{{ $carrier }}')"
                                    icon="heroicon-o-document-arrow-down"
                                    size="sm"
                                >
                                    Generate Manifest
                                </x-filament::button>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-sm text-left">
                                    <thead class="text-xs text-gray-500 uppercase bg-gray-50 dark:bg-white/5 dark:text-gray-400">
                                        <tr>
                                            <th class="px-4 py-2">Tracking Number</th>
                                            <th class="px-4 py-2">Service</th>
                                            <th class="px-4 py-2">Order Ref</th>
                                            <th class="px-4 py-2">Shipped</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach($packages as $package)
                                            <tr>
                                                <td class="px-4 py-2 font-mono text-xs">{{ $package['tracking_number'] }}</td>
                                                <td class="px-4 py-2">{{ $package['service'] }}</td>
                                                <td class="px-4 py-2">{{ $package['order_ref'] }}</td>
                                                <td class="px-4 py-2">{{ $package['shipped_at'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Today's Manifests --}}
        <x-filament::section>
            <x-slot name="heading">Today's Manifests</x-slot>

            @if(empty($todaysManifests))
                <div class="text-center py-8">
                    <x-filament::icon
                        icon="heroicon-o-clipboard-document"
                        class="w-12 h-12 mx-auto text-gray-400"
                    />
                    <p class="mt-2 text-gray-500 dark:text-gray-400">No manifests generated today.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase bg-gray-50 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2">Carrier</th>
                                <th class="px-4 py-2">Manifest Number</th>
                                <th class="px-4 py-2">Packages</th>
                                <th class="px-4 py-2">Time</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($todaysManifests as $manifest)
                                <tr>
                                    <td class="px-4 py-2 font-medium">{{ $manifest['carrier'] }}</td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ $manifest['manifest_number'] }}</td>
                                    <td class="px-4 py-2">{{ $manifest['package_count'] }}</td>
                                    <td class="px-4 py-2">{{ $manifest['created_at'] }}</td>
                                    <td class="px-4 py-2">
                                        @if($manifest['has_image'])
                                            <x-filament::button
                                                wire:click="reprintManifest({{ $manifest['id'] }})"
                                                icon="heroicon-o-printer"
                                                size="xs"
                                                color="gray"
                                            >
                                                Reprint
                                            </x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
