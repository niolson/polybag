<?php

use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('says the validated address is shown when the check returned one', function (): void {
    $shipment = Shipment::factory()->validated()->create();

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertSee('Showing the validated address')
        ->assertDontSee('Not checked yet');
});

it('says the address was checked without corrections when none came back', function (): void {
    $shipment = Shipment::factory()->create([
        'checked' => true,
        'deliverability' => Deliverability::Yes,
        'validated_address1' => null,
        'validation_source' => AddressValidator::Fake,
    ]);

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertSee('Checked, no corrections')
        ->assertSee('Sample validator')
        ->assertDontSee('Not validated');
});

it('says the address is not checked yet only when it was not', function (): void {
    $shipment = Shipment::factory()->create(['checked' => false, 'validated_address1' => null]);

    Livewire::test(ViewShipment::class, ['record' => $shipment->id])
        ->assertSee('Not checked yet')
        ->assertDontSee('Checked, no corrections');
});
