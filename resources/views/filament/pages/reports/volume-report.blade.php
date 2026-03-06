<x-filament-panels::page>
    <div class="mb-6 flex flex-wrap items-center gap-2">
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

        <div class="mx-2 h-6 border-l border-gray-300 dark:border-gray-600"></div>

        <x-filament::button
            :color="$groupBy === 'day' ? 'primary' : 'gray'"
            wire:click="$set('groupBy', 'day')"
            size="sm"
        >
            By Day
        </x-filament::button>
        <x-filament::button
            :color="$groupBy === 'week' ? 'primary' : 'gray'"
            wire:click="$set('groupBy', 'week')"
            size="sm"
        >
            By Week
        </x-filament::button>
        <x-filament::button
            :color="$groupBy === 'month' ? 'primary' : 'gray'"
            wire:click="$set('groupBy', 'month')"
            size="sm"
        >
            By Month
        </x-filament::button>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
