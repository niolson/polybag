<x-filament-panels::page>
    <x-qz-tray />

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
</x-filament-panels::page>
