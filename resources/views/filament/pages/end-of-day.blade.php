<x-filament-panels::page>
    <x-qz-tray />

    <div class="space-y-6">
        {{-- Carrier Summary --}}
        <x-filament::section>
            <x-slot name="heading">Carriers</x-slot>

            @if(empty($carrierSummary))
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">No active carriers configured.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase tracking-wider bg-gray-50 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">Carrier</th>
                                <th class="px-4 py-3">Ship Date</th>
                                <th class="px-4 py-3">Packages</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($carrierSummary as $summary)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                                    <td class="px-4 py-3 font-medium">{{ $summary['carrier'] }}</td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium">{{ $summary['ship_date'] }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($summary['package_count'] > 0)
                                            <span class="font-medium">{{ $summary['package_count'] }}</span>
                                            @if($summary['supports_manifest'] && $summary['unmanifested_count'] > 0)
                                                <span class="inline-flex items-center rounded-full bg-warning-50 dark:bg-warning-950 px-2 py-0.5 text-xs font-medium text-warning-700 dark:text-warning-300 ml-2">
                                                    {{ $summary['unmanifested_count'] }} unmanifested
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-2">
                                        @if($summary['supports_manifest'] && $summary['unmanifested_count'] > 0)
                                            <x-filament::button
                                                wire:click="generateManifest('{{ $summary['carrier'] }}')"
                                                icon="heroicon-o-document-arrow-down"
                                                size="sm"
                                            >
                                                Generate Manifest
                                            </x-filament::button>
                                        @endif
                                        <x-filament::button
                                            wire:click="endShippingDay('{{ $summary['carrier'] }}')"
                                            wire:confirm="End {{ $summary['carrier'] }} shipping day? Ship date will advance from {{ $summary['ship_date'] }} to {{ $summary['next_ship_date'] }}."
                                            icon="heroicon-o-sun"
                                            size="sm"
                                            color="info"
                                        >
                                            End Shipping Day
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Manifests --}}
        <x-filament::section>
            <x-slot name="heading">Manifests</x-slot>

            @if($this->manifests->isEmpty())
                <div class="text-center py-8">
                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                        <x-filament::icon
                            icon="heroicon-o-clipboard-document"
                            class="w-7 h-7 text-gray-400"
                        />
                    </div>
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">No Manifests</h4>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">No manifests have been generated yet.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase tracking-wider bg-gray-50 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">Carrier</th>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Manifest Number</th>
                                <th class="px-4 py-3">Packages</th>
                                <th class="px-4 py-3">Time</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($this->manifests as $manifest)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                                    <td class="px-4 py-3 font-medium">{{ $manifest->carrier }}</td>
                                    <td class="px-4 py-3">{{ \Carbon\Carbon::parse($manifest->manifest_date)->format('M j') }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $manifest->manifest_number }}</td>
                                    <td class="px-4 py-3">{{ $manifest->package_count }}</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $manifest->created_at->tz(\App\Models\Location::timezone())->format('g:i A') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @if(!empty($manifest->image))
                                            <x-filament::button
                                                wire:click="reprintManifest({{ $manifest->id }})"
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

                <div class="mt-4">
                    {{ $this->manifests->links() }}
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
