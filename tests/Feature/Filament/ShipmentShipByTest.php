<?php

use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Models\Location;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    Location::factory()->create(['is_default' => true, 'timezone' => 'America/New_York']);
    Location::clearDefaultCache();
    $this->travelTo('2026-10-20 02:00:00 UTC'); // still Oct 19 in New York
});

it('filters open shipments by ship-by date in the default location timezone', function (): void {
    $overdue = Shipment::factory()->create(['status' => ShipmentStatus::Open, 'ship_by' => '2026-10-18']);
    $today = Shipment::factory()->create(['status' => ShipmentStatus::Open, 'ship_by' => '2026-10-19']);
    $future = Shipment::factory()->create(['status' => ShipmentStatus::Open, 'ship_by' => '2026-10-20']);
    $none = Shipment::factory()->create(['status' => ShipmentStatus::Open, 'ship_by' => null]);
    $shippedLate = Shipment::factory()->create(['status' => ShipmentStatus::Shipped, 'ship_by' => '2026-10-10']);

    Livewire::test(ListShipments::class)
        ->filterTable('ship_by_due', 'today')
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$overdue, $future, $none, $shippedLate]);

    Livewire::test(ListShipments::class)
        ->filterTable('ship_by_due', 'overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$today, $future, $none, $shippedLate]);

    Livewire::test(ListShipments::class)
        ->filterTable('ship_by_due', 'today_or_overdue')
        ->assertCanSeeTableRecords([$overdue, $today])
        ->assertCanNotSeeTableRecords([$future, $none, $shippedLate]);
});

it('offers Ship By as an optional column that starts hidden', function (): void {
    $shipment = Shipment::factory()->create(['ship_by' => '2026-10-16']);

    Livewire::test(ListShipments::class)
        ->assertTableColumnExists('ship_by', fn ($column): bool => $column->isToggleable() && $column->isToggledHiddenByDefault())
        ->toggleAllTableColumns()
        ->assertTableColumnFormattedStateSet('ship_by', 'Oct 16, 2026', $shipment);
});
