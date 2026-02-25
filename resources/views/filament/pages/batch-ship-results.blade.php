<x-filament-panels::page>
    @unless($isComplete)
        <div wire:poll.2s="refreshBatchStatus"></div>
    @endunless

    <x-qz-tray />

    {{-- Progress --}}
    <x-filament::section>
        <div class="space-y-4">
            {{-- Progress bar --}}
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4 overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-500 ease-out {{ $isComplete ? ($labelBatch->status === \App\Enums\LabelBatchStatus::Completed ? 'bg-success-500' : ($labelBatch->status === \App\Enums\LabelBatchStatus::Failed ? 'bg-danger-500' : 'bg-warning-500')) : 'bg-primary-500' }}"
                    style="width: {{ $progressPercent }}%"
                ></div>
            </div>

            {{-- Stats row --}}
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 text-center">
                <div>
                    <div class="text-2xl font-bold">{{ $labelBatch->total_shipments }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Total</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $labelBatch->successful_shipments }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Success</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">{{ $labelBatch->failed_shipments }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Failed</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ $labelBatch->total_shipments - $labelBatch->successful_shipments - $labelBatch->failed_shipments }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Pending</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">${{ number_format($labelBatch->total_cost, 2) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Total Cost</div>
                </div>
            </div>

            {{-- Status badge --}}
            <div class="flex items-center justify-between">
                <x-filament::badge :color="$labelBatch->status->getColor()">
                    {{ $labelBatch->status->getLabel() }}
                </x-filament::badge>

                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Box: {{ $labelBatch->boxSize?->label ?? 'Unknown' }}
                    &middot; Started by {{ $labelBatch->user?->name ?? 'Unknown' }}
                </div>
            </div>

            {{-- Action buttons --}}
            @if($isComplete)
                <div class="flex gap-3">
                    @if($labelBatch->successful_shipments > 0)
                        <x-filament::button
                            wire:click="printAllLabels"
                            icon="heroicon-o-printer"
                        >
                            Print All Labels ({{ $labelBatch->successful_shipments }})
                        </x-filament::button>
                    @endif

                    <x-filament::button
                        href="/shipments"
                        tag="a"
                        color="gray"
                        icon="heroicon-o-arrow-left"
                    >
                        Back to Shipments
                    </x-filament::button>
                </div>
            @endif
        </div>
    </x-filament::section>

    {{-- Results table --}}
    <x-filament::section>
        <x-slot name="heading">Results</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-gray-500 dark:text-gray-400 uppercase border-b dark:border-gray-700">
                    <tr>
                        <th class="px-4 py-3">Reference</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Tracking</th>
                        <th class="px-4 py-3">Carrier / Service</th>
                        <th class="px-4 py-3 text-right">Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($items as $item)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $item['reference'] }}</td>
                            <td class="px-4 py-3">{{ $item['name'] }}</td>
                            <td class="px-4 py-3">
                                <x-filament::badge :color="$item['status']->getColor()">
                                    {{ $item['status']->getLabel() }}
                                </x-filament::badge>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ $item['tracking_number'] ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($item['carrier'])
                                    {{ $item['carrier'] }} / {{ $item['service'] }}
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($item['cost'])
                                    ${{ number_format($item['cost'], 2) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        @if($item['error_message'])
                            <tr>
                                <td colspan="6" class="px-4 py-2 text-xs text-danger-600 dark:text-danger-400 bg-danger-50 dark:bg-danger-950">
                                    {{ $item['error_message'] }}
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
