<?php

use App\Enums\Role;
use App\Filament\Pages\EndOfDay;
use App\Models\Carrier;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows all active carriers with ship dates', function (): void {
    Carrier::factory()->create(['name' => 'USPS', 'active' => true]);
    Carrier::factory()->create(['name' => 'FedEx', 'active' => true]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value) {
            $byCarrier = collect($value)->keyBy('carrier');

            return $byCarrier->has('USPS')
                && $byCarrier->has('FedEx')
                && ! empty($byCarrier['USPS']['ship_date'])
                && ! empty($byCarrier['FedEx']['ship_date']);
        });
});

it('shows unmanifested count for USPS packages shipped today', function (): void {
    Carrier::factory()->create(['name' => 'USPS', 'active' => true]);

    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400111']);
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400222']);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value) {
            $usps = collect($value)->firstWhere('carrier', 'USPS');

            return $usps['unmanifested_count'] === 2;
        });
});

it('excludes already-manifested USPS packages from count', function (): void {
    Carrier::factory()->create(['name' => 'USPS', 'active' => true]);

    $manifest = Manifest::factory()->create();
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'manifest_id' => $manifest->id,
        'manifested' => true,
    ]);
    Package::factory()->shipped()->create(['carrier' => 'USPS', 'tracking_number' => '9400222']);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value) {
            $usps = collect($value)->firstWhere('carrier', 'USPS');

            return $usps['unmanifested_count'] === 1;
        });
});

it('shows zero unmanifested count for non-USPS carriers', function (): void {
    Carrier::factory()->create(['name' => 'FedEx', 'active' => true]);
    Package::factory()->shipped()->create(['carrier' => 'FedEx', 'tracking_number' => '7890001']);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value) {
            $fedex = collect($value)->firstWhere('carrier', 'FedEx');

            return $fedex['unmanifested_count'] === 0;
        });
});

it('does not show inactive carriers', function (): void {
    Carrier::factory()->create(['name' => 'USPS', 'active' => true]);
    Carrier::factory()->create(['name' => 'DHL', 'active' => false]);

    Livewire::test(EndOfDay::class)
        ->assertSet('carrierSummary', function ($value) {
            $carriers = collect($value)->pluck('carrier');

            return $carriers->contains('USPS') && ! $carriers->contains('DHL');
        });
});

it('displays manifests', function (): void {
    Manifest::factory()->create([
        'carrier' => 'USPS',
        'manifest_number' => 'MN999',
        'package_count' => 5,
        'manifest_date' => today(),
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSee('MN999');
});

it('shows manifests from all dates sorted newest first', function (): void {
    Manifest::factory()->create([
        'manifest_number' => 'MN001',
        'manifest_date' => today()->subDay(),
        'created_at' => now()->subDay(),
    ]);
    Manifest::factory()->create([
        'manifest_number' => 'MN002',
        'manifest_date' => today(),
        'created_at' => now(),
    ]);

    Livewire::test(EndOfDay::class)
        ->assertSeeInOrder(['MN002', 'MN001']);
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

it('endShippingDay advances the ship date', function (): void {
    Carrier::factory()->create(['name' => 'FedEx', 'active' => true]);

    $component = Livewire::test(EndOfDay::class);

    $initialDate = collect($component->get('carrierSummary'))->firstWhere('carrier', 'FedEx')['ship_date'];

    $component->call('endShippingDay', 'FedEx')
        ->assertNotified();

    $newDate = collect($component->get('carrierSummary'))->firstWhere('carrier', 'FedEx')['ship_date'];

    expect($newDate)->not->toBe($initialDate);
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
