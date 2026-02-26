<x-filament-panels::page>
    <div class="mb-6">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Total Potential Savings</div>
            <div class="text-2xl font-bold">${{ number_format($this->getTotalPotentialSavings(), 2) }}</div>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
