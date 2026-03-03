<?php

use App\Filament\Widgets\StatsOverview;
use App\Models\DailyShippingStat;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
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
    // Summary stats for today
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 5,
        'total_cost' => 0,
        'total_weight' => 0,
    ]);

    // Summary stats for yesterday (should not be counted in "today")
    DailyShippingStat::create([
        'date' => now()->subDay()->toDateString(),
        'package_count' => 2,
        'total_cost' => 0,
        'total_weight' => 0,
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
