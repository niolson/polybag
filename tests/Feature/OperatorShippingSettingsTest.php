<?php

use App\Enums\Role;
use App\Filament\Pages\OperatorShippingSettings;
use App\Models\Location;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('forbids shippers from managing auto-ship settings', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    Livewire::test(OperatorShippingSettings::class)->assertForbidden();
});

it('lets a manager change auto-ship for shippers at their location only', function (): void {
    $managerLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $manager = User::factory()->manager()->create(['location_id' => $managerLocation]);
    $localShipper = User::factory()->create([
        'role' => Role::User,
        'location_id' => $managerLocation,
        'auto_ship_enabled' => true,
    ]);
    $otherShipper = User::factory()->create([
        'role' => Role::User,
        'location_id' => $otherLocation,
        'auto_ship_enabled' => true,
    ]);
    $this->actingAs($manager);

    Livewire::test(OperatorShippingSettings::class)
        ->assertCanSeeTableRecords([$localShipper])
        ->assertCanNotSeeTableRecords([$otherShipper])
        ->callAction(TestAction::make('disableAutoShip')->table($localShipper));

    expect($localShipper->refresh()->auto_ship_enabled)->toBeFalse()
        ->and($otherShipper->refresh()->auto_ship_enabled)->toBeTrue();
});

it('includes unassigned shippers for a manager at the default location', function (): void {
    $defaultLocation = Location::factory()->create(['is_default' => true]);
    $manager = User::factory()->manager()->create(['location_id' => $defaultLocation]);
    $unassignedShipper = User::factory()->create([
        'role' => Role::User,
        'location_id' => null,
    ]);
    $this->actingAs($manager);

    Livewire::test(OperatorShippingSettings::class)
        ->assertCanSeeTableRecords([$unassignedShipper]);
});

it('lets an admin manage shippers across locations', function (): void {
    $admin = User::factory()->admin()->create();
    $first = User::factory()->create(['location_id' => Location::factory()->create()]);
    $second = User::factory()->create(['location_id' => Location::factory()->create()]);
    $this->actingAs($admin);

    Livewire::test(OperatorShippingSettings::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->callAction(TestAction::make('disableAutoShip')->table($second));

    expect($second->refresh()->auto_ship_enabled)->toBeFalse();
});
