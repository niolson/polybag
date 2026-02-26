<x-filament-panels::page>
    <div class="mb-6 flex gap-2">
        <x-filament::button
            :color="$groupBy === 'channel' ? 'primary' : 'gray'"
            wire:click="$set('groupBy', 'channel')"
            size="sm"
        >
            By Channel
        </x-filament::button>
        <x-filament::button
            :color="$groupBy === 'shipping_method' ? 'primary' : 'gray'"
            wire:click="$set('groupBy', 'shipping_method')"
            size="sm"
        >
            By Shipping Method
        </x-filament::button>
        <x-filament::button
            :color="$groupBy === 'period' ? 'primary' : 'gray'"
            wire:click="$set('groupBy', 'period')"
            size="sm"
        >
            By Month
        </x-filament::button>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
