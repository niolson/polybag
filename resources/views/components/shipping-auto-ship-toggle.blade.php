@props([
    'enabledVariable' => 'autoShipEnabled',
])

<button
    type="button"
    {{ $attributes }}
    :class="{{ $enabledVariable }}
        ? 'fi-btn fi-color-custom fi-btn-color-success fi-color-success fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 disabled:opacity-50 disabled:pointer-events-none'
        : 'fi-btn fi-color-custom fi-btn-color-gray fi-color-gray fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-ac-action fi-ac-btn-action shadow-sm bg-white text-gray-950 hover:bg-gray-50 focus-visible:ring-primary-600/20 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20 disabled:opacity-50 disabled:pointer-events-none'"
>
    <x-filament::icon
        x-show="{{ $enabledVariable }}"
        icon="heroicon-s-bolt"
        class="fi-btn-icon h-5 w-5"
    />
    <x-filament::icon
        x-show="!{{ $enabledVariable }}"
        x-cloak
        icon="heroicon-o-bolt"
        class="fi-btn-icon h-5 w-5"
    />
    <span x-text="{{ $enabledVariable }} ? 'Auto Ship: ON' : 'Auto Ship: OFF'"></span>
</button>
