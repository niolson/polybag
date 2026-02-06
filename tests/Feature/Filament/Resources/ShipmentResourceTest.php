<?php

use App\Enums\Role;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('displays shipped column in shipment table', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $notShipped = Shipment::factory()->create(['shipped' => false]);

    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$shipped, $notShipped]);
});

it('filters shipments by shipped status', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $notShipped = Shipment::factory()->create(['shipped' => false]);

    Livewire::test(ListShipments::class)
        ->filterTable('shipped', true)
        ->assertCanSeeTableRecords([$shipped])
        ->assertCanNotSeeTableRecords([$notShipped]);

    Livewire::test(ListShipments::class)
        ->filterTable('shipped', false)
        ->assertCanSeeTableRecords([$notShipped])
        ->assertCanNotSeeTableRecords([$shipped]);
});
