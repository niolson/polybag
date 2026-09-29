<?php

use App\Enums\Role;
use App\Filament\Widgets\ShippedShipmentsChart;
use App\Models\DailyShippingStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the chart heading', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(ShippedShipmentsChart::class)
        ->assertSee('Shipped Shipments');
});

it('supports week and month filters', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(ShippedShipmentsChart::class)
        ->assertSee('Last 7 days')
        ->assertSee('Last 30 days');
});

it('renders with daily shipping stats', function (): void {
    DailyShippingStat::create([
        'date' => now()->toDateString(),
        'package_count' => 5,
        'total_cost' => '60.00',
    ]);

    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(ShippedShipmentsChart::class)
        ->assertSee('Shipped Shipments');
});

it('switches to month filter', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(ShippedShipmentsChart::class)
        ->set('filter', 'month')
        ->assertSee('Shipped Shipments');
});
