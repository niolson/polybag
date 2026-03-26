<x-filament-panels::page>
    <div class="mx-auto max-w-md">
        <x-filament::section>
            <x-slot name="heading">Your password has expired</x-slot>
            <x-slot name="description">Please choose a new password to continue.</x-slot>

            <form wire:submit="changePassword" class="space-y-4">
                {{ $this->form }}

                <x-filament::button type="submit" class="w-full">
                    Change Password
                </x-filament::button>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
