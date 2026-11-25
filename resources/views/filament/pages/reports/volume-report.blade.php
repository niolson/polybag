<x-filament-panels::page>
    <div class="mb-6 flex items-center gap-3">
        <label for="volume-group-by" class="text-sm font-medium text-gray-700 dark:text-gray-200">Group by</label>
        <x-filament::input.wrapper class="w-56">
            <x-filament::input.select id="volume-group-by" wire:model.live="groupBy">
                @foreach ($this->groupings() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
