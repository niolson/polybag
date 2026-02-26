<?php

use App\Filament\Widgets\CarrierBreakdownChart;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders carrier breakdown chart', function () {
    Package::factory()->usps()->create(['shipped_at' => now()]);
    Package::factory()->fedex()->create(['shipped_at' => now()]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CarrierBreakdownChart::class)
        ->assertSee('Carrier Breakdown');
});

it('supports week and month filters', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CarrierBreakdownChart::class)
        ->assertSee('This Week')
        ->assertSee('This Month');
});
