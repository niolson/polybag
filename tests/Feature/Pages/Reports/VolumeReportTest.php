<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\VolumeReport;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders volume report page', function () {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertOk()
        ->assertSee('Volume Report');
});

it('defaults to channel grouping', function () {
    $user = User::factory()->create(['role' => Role::Manager]);

    $component = Livewire::actingAs($user)
        ->test(VolumeReport::class);

    expect($component->get('groupBy'))->toBe('channel');
});

it('switches to shipping method grouping', function () {
    $user = User::factory()->create(['role' => Role::Manager]);

    Package::factory()->shipped()->create(['shipped_at' => now()]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->set('groupBy', 'shipping_method')
        ->assertOk();
});

it('switches to period grouping', function () {
    $user = User::factory()->create(['role' => Role::Manager]);

    Package::factory()->shipped()->create(['shipped_at' => now()]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->set('groupBy', 'period')
        ->assertOk();
});

it('restricts access to managers and above', function () {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    expect(VolumeReport::canAccess())->toBeFalse();

    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);

    expect(VolumeReport::canAccess())->toBeTrue();
});
