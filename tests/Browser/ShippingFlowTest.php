<?php

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;

it('displays fake rates and completes shipment', function (): void {
    // suppress_printing forces a server-side Livewire redirect instead of QZ Tray print + JS redirect
    Setting::updateOrCreate(
        ['key' => 'suppress_printing'],
        ['value' => '1', 'type' => 'boolean', 'group' => 'system'],
    );

    // Register fake carrier adapters so the Ship page can fetch rates without real API calls.
    // The LaravelHttpServer shares app() with the test, so this persists into HTTP request handling.
    app(CarrierRegistry::class)->registerInstance('USPS', new FakeCarrierAdapter('USPS'));
    app(CarrierRegistry::class)->registerInstance('FedEx', new FakeCarrierAdapter('FedEx'));
    app(CarrierRegistry::class)->registerInstance('UPS', new FakeCarrierAdapter('UPS'));

    $user = User::factory()->create(['role' => Role::Admin]);

    $carrier = Carrier::factory()->usps()->create();
    $carrierService = CarrierService::factory()->uspsGroundAdvantage()->create(['carrier_id' => $carrier->id]);
    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($carrierService->id);

    $shipment = Shipment::factory()->validated()->create([
        'shipping_method_id' => $shippingMethod->id,
        'address1' => '10 Main St',
        'city' => 'Memphis',
        'state_or_province' => 'TN',
        'postal_code' => '38116',
        'country' => 'US',
    ]);

    $boxSize = BoxSize::factory()->create();

    $package = Package::create([
        'shipment_id' => $shipment->id,
        'box_size_id' => $boxSize->id,
        'weight' => 1.5,
        'length' => $boxSize->length,
        'width' => $boxSize->width,
        'height' => $boxSize->height,
    ]);

    $this->actingAs($user);

    $page = visit('/ship/'.$package->id);

    $page->assertSee('Select Shipping Rate')
        ->assertSee('Ground Advantage');

    $page->click('.fi-ac-btn-action:has-text("Buy & Print Label")')
        ->waitForEvent('load');

    $page->assertPathIs('/pack');

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped);
});
