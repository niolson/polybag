<?php

use App\Enums\ShipmentStatus;
use App\Filament\Widgets\StatsOverview;
use App\Models\DailyShippingStat;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows pending shipments count', function (): void {
    Shipment::factory()->count(3)->create(['status' => ShipmentStatus::Open]);
    Shipment::factory()->count(2)->create(['status' => ShipmentStatus::Shipped]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Open Shipments')
        ->assertSee('3');
});

it('shows shipped today count', function (): void {
    Package::factory()->shipped()->count(2)->create(['shipped_at' => now()]);
    Package::factory()->shipped()->create(['shipped_at' => now()->subDay()]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipped Today')
        ->assertSee('2');
});

it('shows shipped last 7 days count', function (): void {
    Package::factory()->shipped()->count(2)->create(['shipped_at' => now()]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipped Last 7 Days');
});

it('shows shipped last 30 days count', function (): void {
    Package::factory()->shipped()->count(3)->create(['shipped_at' => now()]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipped Last 30 Days');
});

it('totals the last 7 and 30 days from the daily counts, today included', function (): void {
    $today = now(Location::timezone())->startOfDay();

    foreach ([[0, 3], [6, 4], [7, 5], [29, 6], [30, 100]] as [$daysAgo, $count]) {
        DailyShippingStat::create([
            'date' => $today->copy()->subDays($daysAgo)->toDateString(),
            'package_count' => $count,
            'costed_package_count' => $count,
            'total_cost' => 0,
            'total_weight' => 0,
        ]);
    }

    Cache::flush();

    $stats = (fn (): array => $this->getStats())->call(new StatsOverview);
    $values = collect($stats)->mapWithKeys(fn ($stat): array => [$stat->getLabel() => $stat->getValue()]);

    expect($values['Shipped Today'])->toBe('3')
        ->and($values['Shipped Last 7 Days'])->toBe('7')
        ->and($values['Shipped Last 30 Days'])->toBe('18');
});

it('compares the last 7 and 30 days with the period before', function (): void {
    $today = now(Location::timezone())->startOfDay();

    foreach ([[0, 6], [8, 4], [40, 2]] as [$daysAgo, $count]) {
        DailyShippingStat::create([
            'date' => $today->copy()->subDays($daysAgo)->toDateString(),
            'package_count' => $count,
            'costed_package_count' => $count,
            'total_cost' => 0,
            'total_weight' => 0,
        ]);
    }

    Cache::flush();

    $stats = (fn (): array => $this->getStats())->call(new StatsOverview);
    $descriptions = collect($stats)->mapWithKeys(fn ($stat): array => [$stat->getLabel() => $stat->getDescription()]);

    // 7 days: 6 against 4; 30 days: 10 against 2.
    expect($descriptions['Shipped Last 7 Days'])->toBe('50.0% increase on the previous 7 days')
        ->and($descriptions['Shipped Last 30 Days'])->toBe('400.0% increase on the previous 30 days');
});

it('gives no percentage when nothing shipped in the period before', function (): void {
    DailyShippingStat::create([
        'date' => now(Location::timezone())->toDateString(),
        'package_count' => 3,
        'costed_package_count' => 3,
        'total_cost' => 0,
        'total_weight' => 0,
    ]);

    Cache::flush();

    $stats = (fn (): array => $this->getStats())->call(new StatsOverview);

    expect(collect($stats)->first(fn ($stat): bool => $stat->getLabel() === 'Shipped Last 7 Days')->getDescription())
        ->toBe('Nothing shipped in the previous 7 days');
});
