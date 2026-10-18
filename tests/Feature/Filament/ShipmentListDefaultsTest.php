<?php

use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('opens on the Open tab and hides shipped shipments', function (): void {
    $open = Shipment::factory()->create(['status' => ShipmentStatus::Open]);
    $shipped = Shipment::factory()->create(['status' => ShipmentStatus::Shipped]);

    Livewire::test(ListShipments::class)
        ->assertSet('activeStatusTab', 'open')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$shipped]);
});

it('shows 25 rows per page', function (): void {
    Shipment::factory()->count(26)->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->assertSet('tableRecordsPerPage', 25)
        ->assertCountTableRecords(26);
});

it('states the destination of a domestic, foreign and military shipment', function (): void {
    $domestic = Shipment::factory()->create(['country' => 'US', 'city' => 'Washington', 'state_or_province' => 'DC']);
    $foreign = Shipment::factory()->create(['country' => 'DE', 'city' => 'Berlin', 'state_or_province' => null]);
    $military = Shipment::factory()->create(['country' => 'US', 'city' => 'APO', 'state_or_province' => 'AE']);

    Livewire::test(ListShipments::class)
        ->assertTableColumnStateSet('destination', 'Washington, DC', record: $domestic)
        ->assertTableColumnStateSet('destination', 'Germany', record: $foreign)
        ->assertTableColumnStateSet('destination', 'APO, AE', record: $military);
});

it('shows Deliver By without opening the column menu', function (): void {
    Livewire::test(ListShipments::class)
        ->assertTableColumnVisible('deliver_by');
});
