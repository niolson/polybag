<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\RateComparison;
use App\Models\Package;
use App\Models\RateQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders rate comparison page', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(RateComparison::class)
        ->assertOk()
        ->assertSee('Rate Comparison');
});

it('shows packages with multiple rate quotes', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $package = Package::factory()->shipped()->create(['shipped_at' => now()]);
    RateQuote::factory()->create(['package_id' => $package->id, 'carrier' => 'USPS', 'quoted_price' => 8.50, 'selected' => true]);
    RateQuote::factory()->create(['package_id' => $package->id, 'carrier' => 'FedEx', 'quoted_price' => 12.00, 'selected' => false]);

    Livewire::actingAs($user)
        ->test(RateComparison::class)
        ->assertOk();
});

it('calculates total potential savings', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $package = Package::factory()->shipped()->create(['shipped_at' => now()]);
    RateQuote::factory()->create(['package_id' => $package->id, 'carrier' => 'FedEx', 'quoted_price' => 15.00, 'selected' => true]);
    RateQuote::factory()->create(['package_id' => $package->id, 'carrier' => 'USPS', 'quoted_price' => 10.00, 'selected' => false]);

    $component = Livewire::actingAs($user)->test(RateComparison::class);

    // The total potential savings should reflect the difference
    expect($component->instance()->getTotalPotentialSavings())->toBe(5.0);
});

it('restricts access to managers and above', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    expect(RateComparison::canAccess())->toBeFalse();

    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);

    expect(RateComparison::canAccess())->toBeTrue();
});
