<?php

use App\Filament\Pages\ManualShip;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('can render the manual ship page', function (): void {
    Livewire::test(ManualShip::class)->assertSuccessful();
});

it('creates a shipment and redirects to ship page', function (): void {
    Channel::factory()->create(['name' => 'Manual']);
    $box = BoxSize::factory()->create();
    $shippingMethod = ShippingMethod::factory()->create();

    Livewire::test(ManualShip::class)
        ->fillForm([
            'shipment_reference' => 'MAN-1001',
            'first_name' => 'Taylor',
            'last_name' => 'Jones',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'country' => 'US',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'shipping_method_id' => $shippingMethod->id,
            'box_size_id' => $box->id,
            'weight' => 2.5,
            'height' => 10,
            'width' => 8,
            'length' => 6,
        ])
        ->call('ship')
        ->assertHasNoFormErrors();

    $shipment = Shipment::where('shipment_reference', 'MAN-1001')->first();

    expect($shipment)->not->toBeNull()
        ->and($shipment->first_name)->toBe('Taylor')
        ->and($shipment->shipping_method_id)->toBe($shippingMethod->id);

    expect(Package::where('shipment_id', $shipment->id)->exists())->toBeTrue();
});
