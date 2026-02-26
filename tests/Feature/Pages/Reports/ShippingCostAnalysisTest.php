<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\ShippingCostAnalysis;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders shipping cost analysis page', function () {
    $user = User::factory()->create(['role' => Role::Manager]);

    Package::factory()->shipped()->create(['cost' => 10.50, 'shipped_at' => now()]);
    Package::factory()->shipped()->create(['cost' => 20.00, 'shipped_at' => now()]);

    Livewire::actingAs($user)
        ->test(ShippingCostAnalysis::class)
        ->assertOk()
        ->assertSee('Shipping Cost Analysis');
});

it('restricts access to managers and above', function () {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    expect(ShippingCostAnalysis::canAccess())->toBeFalse();

    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);

    expect(ShippingCostAnalysis::canAccess())->toBeTrue();
});

it('shows total and average cost', function () {
    $user = User::factory()->create(['role' => Role::Manager]);

    Package::factory()->shipped()->create(['cost' => 10.00, 'shipped_at' => now()]);
    Package::factory()->shipped()->create(['cost' => 20.00, 'shipped_at' => now()]);

    Livewire::actingAs($user)
        ->test(ShippingCostAnalysis::class)
        ->assertSee('$30.00')  // Total
        ->assertSee('$15.00'); // Average
});
