@props([
    'enabledVariable' => 'autoShipEnabled',
])

@php
    $baseClasses = 'fi-btn fi-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg shadow-sm disabled:opacity-50 disabled:pointer-events-none';

    $enabledClasses = $baseClasses . ' ' . (new \Illuminate\View\ComponentAttributeBag)
        ->color(app(\Filament\Support\View\Components\ButtonComponent::class), 'success')
        ->get('class');

    $disabledClasses = $baseClasses . ' bg-white text-gray-950 hover:bg-gray-50 focus-visible:ring-primary-600/20 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20';
@endphp

<button
    type="button"
    {{ $attributes }}
    :class="{{ $enabledVariable }} ? @js($enabledClasses) : @js($disabledClasses)"
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
