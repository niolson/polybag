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

it('displays status column in shipment table', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $notShipped = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$shipped, $notShipped]);
});

it('filters shipments by status', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $notShipped = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Shipped->value)
        ->assertCanSeeTableRecords([$shipped])
        ->assertCanNotSeeTableRecords([$notShipped]);

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Open->value)
        ->assertCanSeeTableRecords([$notShipped])
        ->assertCanNotSeeTableRecords([$shipped]);
});
