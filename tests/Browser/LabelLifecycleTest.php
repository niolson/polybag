<?php

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;

/**
 * The demo's label lifecycle, end to end in a browser: pack, ship, reprint,
 * void, buy again, reprint (`release-polish` pass 5). A reprint straight after
 * the void must be refused, never act on another Label.
 *
 * QZ Tray is replaced on each page by a recorder, so a print counts as sent
 * and the Ship page follows its redirect back to Scan & Pack.
 */
const LIFECYCLE_SCAN_INPUT = 'input[x-ref="scanInput"]';

function lifecycleScan(mixed $page, string $code): mixed
{
    $page->fill(LIFECYCLE_SCAN_INPUT, $code)->keys(LIFECYCLE_SCAN_INPUT, 'Enter');
    $page->wait(1);

    return $page;
}

/** Stand in for QZ Tray once the page's own connection attempt has settled. */
function withRecordingPrinter(mixed $page): mixed
{
    $page->script("localStorage.setItem('imageLabelPrinter', 'Zebra 4x6');");
    $page->wait(2);
    $page->script('
        window.printed = 0;
        window.qz = window.qz || {};
        qz.websocket = { isActive: () => true };
        // As a QZ Config holds it: setPrinter() wraps a name as { name }.
        qz.configs = { create: (printer) => ({ getPrinter: () => ({ name: printer }) }) };
        qz.print = async () => { window.printed++; };
        true;
    ');

    return $page;
}

/** Buy the selected rate on the Ship page and follow the redirect back to Scan & Pack. */
function buyOnShipPage(mixed $page): mixed
{
    $page->assertSee('Select Shipping Rate');
    withRecordingPrinter($page);

    $page->click('.fi-ac-btn-action:has-text("Buy & Print Label")');
    $page->wait(3);
    $page->assertPathIs('/pack');

    return withRecordingPrinter($page);
}

beforeEach(function (): void {
    foreach (['USPS', 'FedEx', 'UPS'] as $carrier) {
        app(CarrierRegistry::class)->registerInstance($carrier, new FakeCarrierAdapter($carrier));
    }

    $carrierService = CarrierService::factory()->uspsGroundAdvantage()->create(['carrier_id' => Carrier::factory()->usps()->create()->id]);
    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($carrierService->id);

    $this->shipment = Shipment::factory()->validated()->create([
        'shipping_method_id' => $shippingMethod->id,
        'address1' => '10 Main St',
        'city' => 'Memphis',
        'state_or_province' => 'TN',
        'postal_code' => '38116',
        'country' => 'US',
    ]);
    $this->product = Product::factory()->create(['barcode' => '2000000000015', 'weight' => 1.0]);
    ShipmentItem::factory()->create([
        'shipment_id' => $this->shipment->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'transparency' => false,
    ]);
    $this->boxSize = BoxSize::factory()->create(['active' => true]);

    $this->actingAs(User::factory()->create(['role' => Role::Admin, 'auto_ship_enabled' => false]));
});

it('packs, ships, reprints, voids, refuses a reprint, buys again and reprints the new label', function (): void {
    // Pack: box, item, weight, then SHIP sends the Package to the Ship page.
    $page = visit('/pack/'.$this->shipment->id);
    $page->assertSee($this->shipment->shipment_reference);

    lifecycleScan($page, '%B'.$this->boxSize->id);
    lifecycleScan($page, $this->product->barcode);
    $page->fill('input[x-model="weight"]', '1.50');
    lifecycleScan($page, '%CSHIP');
    $page->wait(2);

    $package = Package::where('shipment_id', $this->shipment->id)->sole();
    $page->assertPathIs('/ship/'.$package->id);

    // Ship: buy, print, back to Scan & Pack.
    buyOnShipPage($page);
    $first = $package->fresh()->activeLabel()->sole();
    expect($package->fresh()->status)->toBe(PackageStatus::Shipped);

    // Reprint the label just bought.
    lifecycleScan($page, '%CREPRINT');
    expect($page->script('window.printed'))->toBe(1);

    // Void it.
    lifecycleScan($page, '%CVOID');
    $page->assertSee('Label Voided');
    expect($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($first->fresh()->isVoided())->toBeTrue();

    // A reprint straight after the void is refused and prints nothing.
    lifecycleScan($page, '%CREPRINT');
    $page->assertSee('No Label to Reprint');
    expect($page->script('window.printed'))->toBe(1);

    // Buy again from the Ship page.
    $page->navigate('/ship/'.$package->id);
    buyOnShipPage($page);
    $second = $package->fresh()->activeLabel()->sole();
    expect($second->id)->not->toBe($first->id)
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);

    // REPRINT now prints the new label.
    lifecycleScan($page, '%CREPRINT');
    expect($page->script('window.printed'))->toBe(1)
        ->and(PackageLabel::where('package_id', $package->id)->count())->toBe(2);

    $page->assertNoJavaScriptErrors();
});
