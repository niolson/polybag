<x-filament-panels::page>
    <div class="grid grid-cols-3 gap-4 mb-6">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Weight Mismatches</div>
            <div class="text-2xl font-bold">{{ number_format($this->getWeightMismatchCount()) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Batch Failures</div>
            <div class="text-2xl font-bold">{{ number_format($this->getBatchFailureCount()) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Shipped with Validation Issues</div>
            <div class="text-2xl font-bold">{{ number_format($this->getValidationIssueCount()) }}</div>
        </x-filament::section>
    </div>

    <div class="mb-6 flex gap-2">
        <x-filament::button
            :color="$section === 'weight_mismatches' ? 'primary' : 'gray'"
            wire:click="$set('section', 'weight_mismatches')"
            size="sm"
        >
            Weight Mismatches
        </x-filament::button>
        <x-filament::button
            :color="$section === 'batch_failures' ? 'primary' : 'gray'"
            wire:click="$set('section', 'batch_failures')"
            size="sm"
        >
            Batch Failures
        </x-filament::button>
        <x-filament::button
            :color="$section === 'validation_issues' ? 'primary' : 'gray'"
            wire:click="$set('section', 'validation_issues')"
            size="sm"
        >
            Shipped Despite Issues
        </x-filament::button>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
