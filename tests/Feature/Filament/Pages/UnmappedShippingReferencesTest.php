<?php

use App\Enums\Role;
use App\Filament\Pages\UnmappedShippingReferences;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('renders the page successfully', function (): void {
    Livewire::test(UnmappedShippingReferences::class)
        ->assertSuccessful();
});

it('shows unmapped references with counts', function (): void {
    $method = ShippingMethod::factory()->create();

    // Create 3 shipments with unmapped reference "Express"
    Shipment::factory()->count(3)->create([
        'shipping_method_reference' => 'Express',
        'shipping_method_id' => null,
    ]);

    // Create 2 shipments with unmapped reference "Overnight"
    Shipment::factory()->count(2)->create([
        'shipping_method_reference' => 'Overnight',
        'shipping_method_id' => null,
    ]);

    Livewire::test(UnmappedShippingReferences::class)
        ->assertCanSeeTableRecords(
            Shipment::query()
                ->selectRaw('MIN(id) as id, shipping_method_reference')
                ->whereIn('shipping_method_reference', ['Express', 'Overnight'])
                ->whereNull('shipping_method_id')
                ->groupBy('shipping_method_reference')
                ->get()
        )
        ->assertCountTableRecords(2);
});

it('excludes references that already have aliases', function (): void {
    $method = ShippingMethod::factory()->create();

    // Unmapped reference with no alias
    Shipment::factory()->create([
        'shipping_method_reference' => 'Unmapped',
        'shipping_method_id' => null,
    ]);

    // Reference that already has an alias
    ShippingMethodAlias::factory()->create([
        'reference' => 'Already Mapped',
        'shipping_method_id' => $method->id,
    ]);
    Shipment::factory()->create([
        'shipping_method_reference' => 'Already Mapped',
        'shipping_method_id' => null,
    ]);

    Livewire::test(UnmappedShippingReferences::class)
        ->assertCountTableRecords(1);
});

it('excludes references where shipping_method_id is already set', function (): void {
    $method = ShippingMethod::factory()->create();

    // Shipment with method already assigned
    Shipment::factory()->create([
        'shipping_method_reference' => 'Resolved',
        'shipping_method_id' => $method->id,
    ]);

    // Shipment with no method assigned
    Shipment::factory()->create([
        'shipping_method_reference' => 'Unresolved',
        'shipping_method_id' => null,
    ]);

    Livewire::test(UnmappedShippingReferences::class)
        ->assertCountTableRecords(1);
});

it('assigns a shipping method via the assign action', function (): void {
    $method = ShippingMethod::factory()->create();

    $shipments = Shipment::factory()->count(3)->create([
        'shipping_method_reference' => 'Bulk Express',
        'shipping_method_id' => null,
    ]);

    $record = Shipment::query()
        ->selectRaw('MIN(id) as id, shipping_method_reference')
        ->where('shipping_method_reference', 'Bulk Express')
        ->whereNull('shipping_method_id')
        ->groupBy('shipping_method_reference')
        ->first();

    Livewire::test(UnmappedShippingReferences::class)
        ->callTableAction('assign', $record, [
            'shipping_method_id' => $method->id,
        ])
        ->assertNotified();

    // Alias was created
    expect(ShippingMethodAlias::where('reference', 'Bulk Express')->exists())->toBeTrue();
    $alias = ShippingMethodAlias::where('reference', 'Bulk Express')->first();
    expect($alias->shipping_method_id)->toBe($method->id);

    // All shipments were backfilled
    foreach ($shipments as $shipment) {
        expect($shipment->fresh()->shipping_method_id)->toBe($method->id);
    }
});
