<x-filament-panels::page>
    <x-qz-tray />

    @if ($this->printsFromPickBatches())
        <x-filament::section>
            <x-slot name="heading">Pack slips print from pick batches</x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                Picking is required before shipping, so each Shipment's pack slip prints with its
                pick batch, carrying its tote code.
                @if ($url = $this->pickBatchUrl())
                    <x-filament::link :href="$url">Go to Pick Batches</x-filament::link>
                @endif
            </p>
        </x-filament::section>
    @else
        <x-filament::tabs>
            @foreach (\App\Enums\PackSlipQueueTab::cases() as $tab)
                <x-filament::tabs.item
                    :active="$this->activeTab() === $tab"
                    wire:click="$set('activeTab', '{{ $tab->value }}')"
                >
                    {{ $tab->getLabel() }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        @php($leftOff = $this->leftOffForPickBatches())

        @if ($leftOff['count'] > 0)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ $leftOff['count'] }} {{ str('Shipment')->plural($leftOff['count']) }} left off because
                {{ $leftOff['count'] === 1 ? 'it is' : 'they are' }} in an in-progress pick batch, whose
                slips carry the tote code:
                @foreach ($leftOff['batches'] as $batch)
                    @if ($url = $this->pickBatchUrl($batch))
                        <x-filament::link :href="$url">batch #{{ $batch->id }}</x-filament::link>@if (! $loop->last), @endif
                    @else
                        batch #{{ $batch->id }}@if (! $loop->last), @endif
                    @endif
                @endforeach
            </p>
        @endif

        {{ $this->table }}
    @endif
</x-filament-panels::page>
