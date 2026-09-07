<?php

use App\Enums\Role;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('badges an Amazon-fulfilled shipment in the shipments table', function (): void {
    $fba = Shipment::factory()->create(['metadata' => ['amazon_fulfilled_by' => 'AMAZON']]);
    $mfn = Shipment::factory()->create(['metadata' => ['amazon_fulfilled_by' => 'MERCHANT']]);

    Livewire::test(ListShipments::class)
        ->assertTableColumnStateSet('fulfilled_by', 'FBA', record: $fba)
        ->assertTableColumnStateSet('fulfilled_by', null, record: $mfn);
});

it('shows the FBA notice on the shipment view only for Amazon-fulfilled orders', function (): void {
    $fba = Shipment::factory()->create(['metadata' => ['amazon_fulfilled_by' => 'AMAZON']]);

    Livewire::test(ViewShipment::class, ['record' => $fba->id])
        ->assertSchemaComponentExists('fulfilled_by');

    $mfn = Shipment::factory()->create(['metadata' => []]);

    Livewire::test(ViewShipment::class, ['record' => $mfn->id])
        ->assertSchemaComponentHidden('fulfilled_by');
});
