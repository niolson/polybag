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

it('prints the Pack Next Item command only outside production', function (string $environment, bool $printed): void {
    $this->app['env'] = $environment;
    $this->actingAs(User::factory()->create());

    Livewire::test(PrintCommandBarcodes::class)
        ->assertSet('commands', fn (array $commands): bool => collect($commands)->contains(
            fn (array $command): bool => $command['code'] === '%CNEXT',
        ) === $printed);
})->with([
    'demo' => ['demo', true],
    'local' => ['local', true],
    'production' => ['production', false],
]);

it('labels the ship command as shipping, not buying, since Auto Ship decides that', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(PrintCommandBarcodes::class)
        ->assertSet('commands', fn (array $commands): bool => collect($commands)->contains(
            fn (array $command): bool => $command['code'] === '%CSHIP'
                && $command['label'] === 'Ship'
                && str_contains($command['description'], 'F12'),
        ));
});
