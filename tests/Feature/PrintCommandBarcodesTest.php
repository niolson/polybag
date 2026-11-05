<?php

use App\Filament\Pages\PrintCommandBarcodes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('includes a scale zero command barcode', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(PrintCommandBarcodes::class)
        ->assertSet('commands', fn (array $commands): bool => collect($commands)->contains(
            fn (array $command): bool => $command['code'] === '%CZERO'
                && $command['label'] === 'Zero Scale',
        ));
});

it('includes the OK command that acknowledges a mis-scan alert', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(PrintCommandBarcodes::class)
        ->assertSet('commands', fn (array $commands): bool => collect($commands)->contains(
            fn (array $command): bool => $command['code'] === '%COK'
                && $command['label'] === 'OK',
        ));
});
