<?php

use App\Filament\Widgets\StatsOverview;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('displays pending shipments count', function (): void {
    Shipment::factory()->count(3)->create(['shipped' => false]);
    Shipment::factory()->count(2)->create(['shipped' => true]);

    Livewire::test(StatsOverview::class)
        ->assertSee('Pending Shipments')
        ->assertSee('3');
});

it('displays packages shipped today count', function (): void {
    // Packages shipped today
    Package::factory()->count(5)->create([
        'shipped' => true,
        'shipped_at' => now(),
    ]);

    // Packages shipped yesterday (should not be counted)
    Package::factory()->count(2)->create([
        'shipped' => true,
        'shipped_at' => now()->subDay(),
    ]);

    // Unshipped packages (should not be counted)
    Package::factory()->count(3)->create([
        'shipped' => false,
        'shipped_at' => null,
    ]);

    Livewire::test(StatsOverview::class)
        ->assertSee('Shipped Today')
        ->assertSee('5');
});

it('shows zero when no pending shipments', function (): void {
    Shipment::factory()->count(2)->create(['shipped' => true]);

    Livewire::test(StatsOverview::class)
        ->assertSee('Pending Shipments')
        ->assertSee('0');
});

it('shows zero when no packages shipped today', function (): void {
    Package::factory()->count(3)->create([
        'shipped' => true,
        'shipped_at' => now()->subDay(),
    ]);

    Livewire::test(StatsOverview::class)
        ->assertSee('Shipped Today')
        ->assertSee('0');
});
