<?php

use App\Enums\Role;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

/*
 * `ship_date` and `deliver_by` are calendar dates stored as UTC midnight.
 * Shifting them into a US timezone moved them to the evening before, so a
 * label bought Oct 16 read Ship Date Oct 15 (ui-review/01, S11).
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    Location::factory()->create(['is_default' => true, 'timezone' => 'America/New_York']);
    Location::clearDefaultCache();
});

it('shows the stored ship date on the package view', function (): void {
    $package = Package::factory()->shipped()->create(['ship_date' => '2026-10-16']);

    Livewire::test(ViewPackage::class, ['record' => $package->id])
        ->assertSee('Oct 16, 2026')
        ->assertDontSee('Oct 15, 2026');
});

it('shows the stored deliver-by date on the shipment list and view', function (): void {
    $shipment = Shipment::factory()->create(['deliver_by' => '2026-10-16']);

    Livewire::test(ListShipments::class)
        ->toggleAllTableColumns()
        ->assertTableColumnFormattedStateSet('deliver_by', 'Oct 16, 2026', $shipment);

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertSee('Oct 16, 2026')
        ->assertDontSee('Oct 15, 2026');
});
