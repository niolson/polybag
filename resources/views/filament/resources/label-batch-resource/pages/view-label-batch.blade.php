<x-filament-panels::page>
    @unless($this->record->isComplete())
        <div wire:poll.2s="$refresh"></div>
    @endunless

    <x-qz-tray />

    {{-- Progress --}}
    <x-filament::section>
        <div class="space-y-4">
            {{-- Progress bar --}}
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4 overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-500 ease-out {{ $this->record->isComplete() ? ($this->record->status === \App\Enums\LabelBatchStatus::Completed ? 'bg-success-500' : ($this->record->status === \App\Enums\LabelBatchStatus::Failed ? 'bg-danger-500' : 'bg-warning-500')) : 'bg-primary-500' }}"
                    style="width: {{ $this->getProgressPercent() }}%"
                ></div>
            </div>

            {{-- Stats row --}}
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 text-center">
                <div>
                    <div class="text-2xl font-bold">{{ $this->record->total_shipments }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Total</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $this->record->successful_shipments }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Success</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">{{ $this->record->failed_shipments }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Failed</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ $this->record->total_shipments - $this->record->successful_shipments - $this->record->failed_shipments }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Pending</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">${{ number_format($this->record->total_cost, 2) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Total Cost</div>
                </div>
            </div>

            {{-- Status badge + meta --}}
            <div class="flex items-center justify-between">
                <x-filament::badge :color="$this->record->status->getColor()">
                    {{ $this->record->status->getLabel() }}
                </x-filament::badge>

                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Box: {{ $this->record->boxSize?->label ?? 'Unknown' }}
                    &middot; Started by {{ $this->record->user?->name ?? 'Unknown' }}
                    &middot; {{ $this->record->created_at->tz(\App\Models\Location::timezone())->format('M j, Y g:i A') }}
                </div>
            </div>
        </div>
    </x-filament::section>

    {{ $this->content }}

    <x-filament-actions::modals />
</x-filament-panels::page>
