<x-filament-panels::page>
    <x-qz-tray />

    <div class="space-y-6">
        {{-- Unmanifested Packages --}}
        <x-filament::section>
            <x-slot name="heading">Unmanifested Packages</x-slot>

            @if(empty($carrierSummary))
                <div class="text-center py-8">
                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-success-50 dark:bg-success-950">
                        <x-filament::icon
                            icon="heroicon-o-check-circle"
                            class="w-7 h-7 text-success-500"
                        />
                    </div>
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">All Clear</h4>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">All packages have been manifested.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase tracking-wider bg-gray-50 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">Carrier</th>
                                <th class="px-4 py-3">Unmanifested Packages</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($carrierSummary as $summary)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                                    <td class="px-4 py-3 font-medium">{{ $summary['carrier'] }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full bg-warning-50 dark:bg-warning-950 px-2 py-0.5 text-xs font-medium text-warning-700 dark:text-warning-300">
                                            {{ $summary['count'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
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
                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                        <x-filament::icon
                            icon="heroicon-o-clipboard-document"
                            class="w-7 h-7 text-gray-400"
                        />
                    </div>
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">No Manifests Yet</h4>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">No manifests have been generated today.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase tracking-wider bg-gray-50 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">Carrier</th>
                                <th class="px-4 py-3">Manifest Number</th>
                                <th class="px-4 py-3">Packages</th>
                                <th class="px-4 py-3">Time</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($todaysManifests as $manifest)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                                    <td class="px-4 py-3 font-medium">{{ $manifest['carrier'] }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $manifest['manifest_number'] }}</td>
                                    <td class="px-4 py-3">{{ $manifest['package_count'] }}</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $manifest['created_at'] }}</td>
                                    <td class="px-4 py-3 text-right">
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
