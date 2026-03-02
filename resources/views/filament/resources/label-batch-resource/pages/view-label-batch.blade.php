<x-filament-panels::page>
    @unless($this->record->isComplete())
        <div wire:poll.2s="$refresh"></div>
    @endunless

    <x-qz-tray />

    {{ $this->infolist }}

    <x-filament-actions::modals />
</x-filament-panels::page>
