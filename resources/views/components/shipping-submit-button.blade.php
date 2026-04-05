@props([
    'enabledVariable' => 'autoShipEnabled',
    'loadingType' => 'alpine',
    'loadingVariable' => 'isShipping',
])

<button
    {{ $attributes->merge([
        'class' => 'fi-btn fi-color-custom fi-btn-color-primary fi-color-primary fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 disabled:opacity-50 disabled:pointer-events-none',
    ]) }}
>
    @if ($loadingType === 'wire')
        <x-filament::icon
            wire:loading.remove
            icon="heroicon-o-paper-airplane"
            class="fi-btn-icon h-5 w-5"
        />
        <x-filament::loading-indicator wire:loading class="h-5 w-5" />
        <span wire:loading.remove x-text="{{ $enabledVariable }} ? 'Auto Ship' : 'Ship'"></span>
        <span wire:loading>Working...</span>
    @else
        <template x-if="{{ $loadingVariable }}">
            <x-filament::loading-indicator class="h-5 w-5" />
        </template>
        <template x-if="!{{ $loadingVariable }}">
            <x-filament::icon
                icon="heroicon-o-paper-airplane"
                class="fi-btn-icon h-5 w-5"
            />
        </template>
        <span x-text="{{ $enabledVariable }} ? 'Auto Ship' : 'Ship'"></span>
    @endif
</button>
