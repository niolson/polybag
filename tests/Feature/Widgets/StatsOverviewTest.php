<?php

use App\Enums\ShipmentStatus;
use App\Filament\Widgets\StatsOverview;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows pending shipments count', function () {
    Shipment::factory()->count(3)->create(['status' => ShipmentStatus::Open]);
    Shipment::factory()->count(2)->create(['status' => ShipmentStatus::Shipped]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Pending Shipments')
        ->assertSee('3');
});

it('shows shipped today count', function () {
    Package::factory()->shipped()->count(2)->create(['shipped_at' => now()]);
    Package::factory()->shipped()->create(['shipped_at' => now()->subDay()]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipped Today')
        ->assertSee('2');
});

it('shows shipped this week count', function () {
    Package::factory()->shipped()->count(2)->create(['shipped_at' => now()]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipped This Week');
});

it('shows shipped this month count', function () {
    Package::factory()->shipped()->count(3)->create(['shipped_at' => now()]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipped This Month');
});
