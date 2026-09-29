@props([
    'enabledVariable' => 'autoShipEnabled',
    'loadingType' => 'alpine',
    'loadingVariable' => 'isShipping',
    'loadingTarget' => null,
    'label' => null,
])

@php
    $colorClasses = (new \Illuminate\View\ComponentAttributeBag)
        ->color(app(\Filament\Support\View\Components\ButtonComponent::class), 'primary')
        ->get('class');
@endphp

<button
    {{ $attributes->merge([
        'class' => 'fi-btn fi-size-md gap-1.5 px-3 py-2 text-sm inline-grid grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg shadow-sm disabled:opacity-50 disabled:pointer-events-none ' . $colorClasses,
    ]) }}
    @unless ($label)
        x-bind:title="{{ $enabledVariable }} ? 'Buys and prints a label now: the service a shipping rule picks, otherwise the cheapest rate this shipping method allows. Opens the rate list if nothing can be bought automatically.' : 'Opens the rate list to compare rates and choose a shipping service.'"
    @endunless
>
    @if ($loadingType === 'wire')
        @if ($loadingTarget)
            <x-filament::icon wire:loading.remove wire:target="{{ $loadingTarget }}" icon="heroicon-o-paper-airplane" class="fi-btn-icon h-5 w-5" />
            <x-filament::loading-indicator wire:loading wire:target="{{ $loadingTarget }}" class="h-5 w-5" />
            @if ($label)
                <span wire:loading.remove wire:target="{{ $loadingTarget }}">{{ $label }}</span>
            @else
                <span wire:loading.remove wire:target="{{ $loadingTarget }}" x-text="{{ $enabledVariable }} ? 'Buy & print label' : 'Choose shipping service'"></span>
            @endif
            <span wire:loading wire:target="{{ $loadingTarget }}">Working...</span>
        @else
            <x-filament::icon wire:loading.remove icon="heroicon-o-paper-airplane" class="fi-btn-icon h-5 w-5" />
            <x-filament::loading-indicator wire:loading class="h-5 w-5" />
            @if ($label)
                <span wire:loading.remove>{{ $label }}</span>
            @else
                <span wire:loading.remove x-text="{{ $enabledVariable }} ? 'Buy & print label' : 'Choose shipping service'"></span>
            @endif
            <span wire:loading>Working...</span>
        @endif
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
        @if ($label)
            <span>{{ $label }}</span>
        @else
            <span x-text="{{ $enabledVariable }} ? 'Buy & print label' : 'Choose shipping service'"></span>
        @endif
    @endif
</button>
