<x-filament-panels::page>
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="flex gap-2">
            <x-filament::button
                :color="$viewMode === 'all' ? 'primary' : 'gray'"
                wire:click="$set('viewMode', 'all')"
                size="sm"
            >
                All Users
            </x-filament::button>
            <x-filament::button
                :color="$viewMode === 'individual' ? 'primary' : 'gray'"
                wire:click="$set('viewMode', 'individual')"
                size="sm"
            >
                By User
            </x-filament::button>
        </div>

        @if ($viewMode === 'individual')
            <div class="flex items-center gap-4">
                <select
                    wire:model.live="userId"
                    class="fi-select-input rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white text-sm"
                >
                    @foreach ($this->getUserOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>

                <div class="mx-1 h-6 border-l border-gray-300 dark:border-gray-600"></div>

                <div class="flex gap-2">
                    <x-filament::button
                        :color="$period === 'day' ? 'primary' : 'gray'"
                        wire:click="$set('period', 'day')"
                        size="sm"
                    >
                        Daily
                    </x-filament::button>
                    <x-filament::button
                        :color="$period === 'week' ? 'primary' : 'gray'"
                        wire:click="$set('period', 'week')"
                        size="sm"
                    >
                        Weekly
                    </x-filament::button>
                    <x-filament::button
                        :color="$period === 'month' ? 'primary' : 'gray'"
                        wire:click="$set('period', 'month')"
                        size="sm"
                    >
                        Monthly
                    </x-filament::button>
                </div>
            </div>
        @endif
    </div>

    {{ $this->table }}
</x-filament-panels::page>
