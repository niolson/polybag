<?php

use App\Enums\Role;
use App\Filament\Pages\UnmappedShippingReferences;
use App\Models\Client;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('renders the page successfully', function (): void {
    Livewire::test(UnmappedShippingReferences::class)
        ->assertSuccessful();
});

it('shows unmapped references with counts', function (): void {
    ShippingMethod::factory()->create();

    Shipment::factory()->count(3)->create([
        'shipping_method_reference' => 'Express',
        'shipping_method_id' => null,
    ]);

    Shipment::factory()->count(2)->create([
        'shipping_method_reference' => 'Overnight',
        'shipping_method_id' => null,
    ]);

    Livewire::test(UnmappedShippingReferences::class)
        ->assertCanSeeTableRecords(
            Shipment::query()
                ->selectRaw('MIN(id) as id, shipping_method_reference, client_id')
                ->whereIn('shipping_method_reference', ['Express', 'Overnight'])
                ->whereNull('shipping_method_id')
                ->groupBy('shipping_method_reference', 'client_id')
                ->get()
        )
        ->assertCountTableRecords(2);
});

it('excludes references that already have an alias for that client', function (): void {
    $method = ShippingMethod::factory()->create();

    Shipment::factory()->create([
        'shipping_method_reference' => 'Unmapped',
        'shipping_method_id' => null,
    ]);

    $aliasedShipment = Shipment::factory()->create([
        'shipping_method_reference' => 'Already Mapped',
        'shipping_method_id' => null,
    ]);

    ShippingMethodAlias::factory()->create([
        'client_id' => $aliasedShipment->client_id,
        'reference' => 'Already Mapped',
        'shipping_method_id' => $method->id,
    ]);

    Livewire::test(UnmappedShippingReferences::class)
        ->assertCountTableRecords(1);
});

it('does not exclude a reference mapped only for a different client', function (): void {
    $method = ShippingMethod::factory()->create();
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    Shipment::factory()->create([
        'client_id' => $clientA->id,
        'shipping_method_reference' => 'Express',
        'shipping_method_id' => null,
    ]);

    Shipment::factory()->create([
        'client_id' => $clientB->id,
        'shipping_method_reference' => 'Express',
        'shipping_method_id' => null,
    ]);

    // Alias only exists for client A
    ShippingMethodAlias::factory()->create([
        'client_id' => $clientA->id,
        'reference' => 'Express',
        'shipping_method_id' => $method->id,
    ]);

    // Client B's "Express" is still unmapped — should appear
    Livewire::test(UnmappedShippingReferences::class)
        ->assertCountTableRecords(1);
});

it('shows one row per client+reference combination', function (): void {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    Shipment::factory()->count(2)->create([
        'client_id' => $clientA->id,
        'shipping_method_reference' => 'Ground',
        'shipping_method_id' => null,
    ]);

    Shipment::factory()->count(3)->create([
        'client_id' => $clientB->id,
        'shipping_method_reference' => 'Ground',
        'shipping_method_id' => null,
    ]);

    Livewire::test(UnmappedShippingReferences::class)
        ->assertCountTableRecords(2);
});

it('excludes references where shipping_method_id is already set', function (): void {
    $method = ShippingMethod::factory()->create();

    Shipment::factory()->create([
        'shipping_method_reference' => 'Resolved',
        'shipping_method_id' => $method->id,
    ]);

    Shipment::factory()->create([
        'shipping_method_reference' => 'Unresolved',
        'shipping_method_id' => null,
    ]);

    Livewire::test(UnmappedShippingReferences::class)
        ->assertCountTableRecords(1);
});

it('assigns a shipping method and only updates shipments for that client', function (): void {
    $method = ShippingMethod::factory()->create();
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $shipmentsA = Shipment::factory()->count(3)->create([
        'client_id' => $clientA->id,
        'shipping_method_reference' => 'Bulk Express',
        'shipping_method_id' => null,
    ]);

    $shipmentsB = Shipment::factory()->count(2)->create([
        'client_id' => $clientB->id,
        'shipping_method_reference' => 'Bulk Express',
        'shipping_method_id' => null,
    ]);

    $record = Shipment::query()
        ->selectRaw('MIN(id) as id, shipping_method_reference, client_id')
        ->where('shipping_method_reference', 'Bulk Express')
        ->where('client_id', $clientA->id)
        ->whereNull('shipping_method_id')
        ->groupBy('shipping_method_reference', 'client_id')
        ->first();

    Livewire::test(UnmappedShippingReferences::class)
        ->callTableAction('assign', $record, [
            'shipping_method_id' => $method->id,
        ])
        ->assertNotified();

    // Alias created for client A
    expect(ShippingMethodAlias::where('reference', 'Bulk Express')->where('client_id', $clientA->id)->exists())->toBeTrue();

    // Client A's shipments backfilled
    foreach ($shipmentsA as $shipment) {
        expect($shipment->fresh()->shipping_method_id)->toBe($method->id);
    }

    // Client B's shipments untouched
    foreach ($shipmentsB as $shipment) {
        expect($shipment->fresh()->shipping_method_id)->toBeNull();
    }
});

it('assigns a shipping method via the assign action (single client)', function (): void {
    $method = ShippingMethod::factory()->create();

    $shipments = Shipment::factory()->count(3)->create([
        'shipping_method_reference' => 'Standard',
        'shipping_method_id' => null,
    ]);

    $record = Shipment::query()
        ->selectRaw('MIN(id) as id, shipping_method_reference, client_id')
        ->where('shipping_method_reference', 'Standard')
        ->whereNull('shipping_method_id')
        ->groupBy('shipping_method_reference', 'client_id')
        ->first();

    Livewire::test(UnmappedShippingReferences::class)
        ->callTableAction('assign', $record, [
            'shipping_method_id' => $method->id,
        ])
        ->assertNotified();

    expect(ShippingMethodAlias::where('reference', 'Standard')->exists())->toBeTrue();

    foreach ($shipments as $shipment) {
        expect($shipment->fresh()->shipping_method_id)->toBe($method->id);
    }
});

it('clears the validation attempt on Shipments it assigns a method to', function (): void {
    $method = ShippingMethod::factory()->create();

    $shipment = Shipment::factory()->validationExhausted()->create([
        'shipping_method_reference' => 'Standard',
        'shipping_method_id' => null,
    ]);

    $record = Shipment::query()
        ->selectRaw('MIN(id) as id, shipping_method_reference, client_id')
        ->where('shipping_method_reference', 'Standard')
        ->whereNull('shipping_method_id')
        ->groupBy('shipping_method_reference', 'client_id')
        ->first();

    Livewire::test(UnmappedShippingReferences::class)
        ->callTableAction('assign', $record, [
            'shipping_method_id' => $method->id,
        ]);

    expect($shipment->fresh()->validation_attempted_at)->toBeNull();
});
