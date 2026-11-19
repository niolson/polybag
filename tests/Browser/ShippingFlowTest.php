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

/**
 * A packed Package ready for the Ship page, with fake carrier rates to choose from.
 */
function packageWithFakeRates(): Package
{
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

    return $package;
}

/** Open the Ship page for a Package as an admin. */
function visitShipPage(Package $package): mixed
{
    $page = visit('/ship/'.$package->id);

    $page->assertSee('Select Shipping Rate')
        ->assertSee('Ground Advantage');

    return $page;
}

it('displays fake rates and completes shipment', function (): void {
    $package = packageWithFakeRates();
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $page = visitShipPage($package);

    $page->click('.fi-ac-btn-action:has-text("Buy & Print Label")')
        ->waitForEvent('load');

    $page->assertPathIs('/pack');

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('buys through the Buy button when the SHIP barcode is scanned', function (): void {
    $package = packageWithFakeRates();
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $page = visitShipPage($package);

    // Count presses of the button, which is what shows its spinner and disables it.
    $page->script("
        window.shipClicks = 0;
        document.querySelector('[data-scan-command=ship]').addEventListener('click', () => window.shipClicks++);
        true;
    ");

    // A scanner types into the page, not into a field, and ends with Enter.
    $page->script("
        for (const key of [...'%cship', 'Enter']) {
            window.dispatchEvent(new KeyboardEvent('keydown', { key }));
        }
        true;
    ");
    expect($page->script('window.shipClicks'))->toBe(1);

    $page->waitForEvent('load');
    $page->assertPathIs('/pack');

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped);
});
