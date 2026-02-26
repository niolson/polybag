<?php

use App\Filament\Widgets\StatsOverview;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows shipping cost this week', function () {
    Package::factory()->shipped()->create(['shipped_at' => now(), 'cost' => 10.50]);
    Package::factory()->shipped()->create(['shipped_at' => now(), 'cost' => 15.25]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipping Cost This Week')
        ->assertSee('$25.75');
});

it('shows zero when no packages shipped this week', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$0.00');
});
