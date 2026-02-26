<x-filament-panels::page>
    <div class="grid grid-cols-3 gap-4 mb-6">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Total Cost</div>
            <div class="text-2xl font-bold">${{ number_format($this->getTotalCost(), 2) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Average Cost</div>
            <div class="text-2xl font-bold">${{ number_format($this->getAverageCost(), 2) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Package Count</div>
            <div class="text-2xl font-bold">{{ number_format($this->getPackageCount()) }}</div>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
