@props([
    'groups' => [],
])

<div class="fi-resource-list-tab-groups flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-center lg:gap-10">
    @foreach ($groups as $group)
        @php
            $groupClasses = 'w-full lg:w-auto';
            $tabsClasses = 'justify-start';
        @endphp

        <div @class([$groupClasses])>
            <div class="mb-2 text-center text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ $group['label'] }}
            </div>

            <x-filament::tabs :class="$tabsClasses">
                @foreach ($group['tabs'] as $tabKey => $tabLabel)
                    <x-filament::tabs.item
                        :active="data_get($this, $group['property']) === $tabKey"
                        color="gray"
                        wire:click="{{ '$set(' . '\'' . $group['property'] . '\'' . ', ' . '\'' . $tabKey . '\'' . ')' }}"
                    >
                        {{ $tabLabel }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        </div>
    @endforeach
</div>
