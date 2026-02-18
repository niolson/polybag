<?php

use App\Enums\Role;
use App\Filament\Pages\EndOfDay;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('loads unmanifested package summary by carrier', function (): void {
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111']);
    Package::factory()->shipped()->create(['carrier' => 'FedEx', 'tracking_number' => '7890001']);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value) {
            $byCarrier = collect($value)->keyBy('carrier');

            return $byCarrier->has('USPS')
                && $byCarrier['USPS']['count'] === 1
                && $byCarrier['USPS']['supports_manifest'] === true
                && $byCarrier->has('FedEx')
                && $byCarrier['FedEx']['count'] === 1
                && $byCarrier['FedEx']['supports_manifest'] === false;
        });
});

it('shows empty state when all packages are manifested', function (): void {
    $manifest = Manifest::factory()->create();
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'manifest_id' => $manifest->id,
        'manifested' => true,
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', [])
        ->assertSee('All packages have been manifested.');
});

it('displays todays manifests', function (): void {
    Manifest::factory()->create([
        'carrier' => 'USPS',
        'manifest_number' => 'MN999',
        'package_count' => 5,
        'manifest_date' => today(),
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSet('todaysManifests', fn ($value) => count($value) === 1 && $value[0]['manifest_number'] === 'MN999');
});

it('excludes manifests from other days', function (): void {
    Manifest::factory()->create([
        'manifest_date' => today()->subDay(),
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSet('todaysManifests', []);
});

it('dispatches print-report event when reprinting manifest with image', function (): void {
    $manifest = Manifest::factory()->withImage()->create([
        'manifest_date' => today(),
    ]);

    Livewire::test(EndOfDay::class)
        ->call('reprintManifest', $manifest->id)
        ->assertDispatched('print-report');
});

it('shows error when reprinting manifest without image', function (): void {
    $manifest = Manifest::factory()->create([
        'image' => null,
        'manifest_date' => today(),
    ]);

    Livewire::test(EndOfDay::class)
        ->call('reprintManifest', $manifest->id)
        ->assertNotified();
});

it('marks packages as manifested without a manifest record', function (): void {
    $package = Package::factory()->shipped()->create(['carrier' => 'FedEx', 'tracking_number' => '7890001']);

    Livewire::test(EndOfDay::class)
        ->call('markAsManifested', 'FedEx')
        ->assertNotified();

    $package->refresh();
    expect($package->manifested)->toBeTrue()
        ->and($package->manifest_id)->toBeNull();
});

it('denies access to users with User role', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    Livewire::test(EndOfDay::class)->assertForbidden();
});

it('allows access to managers', function (): void {
    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(EndOfDay::class)->assertSuccessful();
});

it('allows access to admins', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EndOfDay::class)->assertSuccessful();
});
