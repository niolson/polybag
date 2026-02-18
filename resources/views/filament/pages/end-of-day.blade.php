<x-filament-panels::page>
    <x-qz-tray />

    <div class="space-y-6">
        {{-- Unmanifested Packages --}}
        <x-filament::section>
            <x-slot name="heading">Unmanifested Packages</x-slot>

            @if(empty($carrierSummary))
                <div class="text-center py-8">
                    <x-filament::icon
                        icon="heroicon-o-check-circle"
                        class="w-12 h-12 mx-auto text-success-500"
                    />
                    <p class="mt-2 text-gray-500 dark:text-gray-400">All packages have been manifested.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase bg-gray-50 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2">Carrier</th>
                                <th class="px-4 py-2">Unmanifested Packages</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($carrierSummary as $summary)
                                <tr>
                                    <td class="px-4 py-2 font-medium">{{ $summary['carrier'] }}</td>
                                    <td class="px-4 py-2">{{ $summary['count'] }}</td>
                                    <td class="px-4 py-2 text-right">
                                        @if($summary['supports_manifest'])
                                            <x-filament::button
                                                wire:click="generateManifest('{{ $summary['carrier'] }}')"
                                                icon="heroicon-o-document-arrow-down"
                                                size="sm"
                                            >
                                                Generate Manifest
                                            </x-filament::button>
                                        @endif
                                        <x-filament::button
                                            wire:click="markAsManifested('{{ $summary['carrier'] }}')"
                                            wire:confirm="Mark all {{ $summary['count'] }} {{ $summary['carrier'] }} packages as manifested?"
                                            icon="heroicon-o-check"
                                            size="sm"
                                            color="gray"
                                        >
                                            Mark as Manifested
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
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
