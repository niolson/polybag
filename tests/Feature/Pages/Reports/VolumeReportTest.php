<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\VolumeReport;
use App\Models\DailyShippingStat;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders volume report page', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertOk()
        ->assertSee('Volume');
});

it('defaults to channel grouping', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $component = Livewire::actingAs($user)
        ->test(VolumeReport::class);

    expect($component->get('groupBy'))->toBe('channel');
});

it('switches to shipping method grouping', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Package::factory()->shipped()->create(['shipped_at' => now()]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->set('groupBy', 'shipping_method')
        ->assertOk();
});

it('switches to period grouping', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Package::factory()->shipped()->create(['shipped_at' => now()]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->set('groupBy', 'period')
        ->assertOk();
});

it('restricts access to managers and above', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    expect(VolumeReport::canAccess())->toBeFalse();

    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);

    expect(VolumeReport::canAccess())->toBeTrue();
});

it('averages cost over the packages that reported one', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    // Six of ten packages priced, $60 between them. The average is $10 over
    // those six, not $6 over all ten.
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 6,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertOk()
        ->assertSee('$10.00')
        ->assertDontSee('$6.00');
});

it('discloses how many packages the total leaves out', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 6,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertSee('excludes 4 with no reported cost')
        ->assertSee('over 6 priced');
});

it('says nothing about exclusions when every package was priced', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertSee('$6.00')
        ->assertDontSee('no reported cost');
});

it('treats a rollup row from before the column as fully costed', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => null,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertSee('$6.00')
        ->assertDontSee('no reported cost');
});

it('reports an unknown average when nothing in the group was priced', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 4,
        'costed_package_count' => 0,
        'total_cost' => 0.00,
        'total_weight' => 8.00,
    ]);

    Livewire::actingAs($user)
        ->test(VolumeReport::class)
        ->assertSee('Unknown')
        ->assertSee('excludes 4 with no reported cost');
});
