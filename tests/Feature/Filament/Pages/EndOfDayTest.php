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

it('loads unmanifested packages grouped by carrier', function (): void {
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111']);
    Package::factory()->shipped()->create(['carrier' => 'FedEx', 'tracking_number' => '7890001']);

    Livewire::test(EndOfDay::class)
        ->assertSet('unmanifestedByCarrier.USPS', fn ($value) => count($value) === 1)
        ->assertSet('unmanifestedByCarrier.FedEx', fn ($value) => count($value) === 1);
});

it('shows empty state when all packages are manifested', function (): void {
    $manifest = Manifest::factory()->create();
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'manifest_id' => $manifest->id,
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSet('unmanifestedByCarrier', [])
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
