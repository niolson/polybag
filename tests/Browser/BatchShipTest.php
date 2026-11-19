<?php

use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\LabelBatch;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;

/**
 * Batch Ship from the Shipments list to printed labels (`release-polish`
 * pass 5). The queue is synchronous in tests, so the batch has finished by
 * the time its page opens.
 */
beforeEach(function (): void {
    foreach (['USPS', 'FedEx', 'UPS'] as $carrier) {
        app(CarrierRegistry::class)->registerInstance($carrier, new FakeCarrierAdapter($carrier));
    }

    $carrierService = CarrierService::factory()->uspsGroundAdvantage()->create(['carrier_id' => Carrier::factory()->usps()->create()->id]);
    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($carrierService->id);

    $this->shipments = Shipment::factory()->validated()->count(2)->create([
        'shipping_method_id' => $shippingMethod->id,
        'address1' => '10 Main St',
        'city' => 'Memphis',
        'state_or_province' => 'TN',
        'postal_code' => '38116',
        'country' => 'US',
    ]);

    foreach ($this->shipments as $shipment) {
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create(['weight' => 1.0])->id,
            'quantity' => 1,
            'transparency' => false,
        ]);
    }

    $this->boxSize = BoxSize::factory()->create(['label' => 'Demo Mailer']);

    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('buys a label for each selected Shipment and prints the batch', function (): void {
    $page = visit('/shipments');

    foreach ($this->shipments as $shipment) {
        $page->assertSee($shipment->shipment_reference);
    }

    $page->click('.fi-ta-page-checkbox');
    $page->click('.fi-ta-header-toolbar button:has-text("Batch Ship")');
    $page->assertSee('Buy a label for each selected shipment');

    $page->click('.fi-modal-window .fi-select-input-placeholder');
    $page->click('[role="option"]:has-text("Demo Mailer")');
    $page->click('.fi-modal-window button:has-text("Buy Labels")');
    $page->wait(3);

    $batch = LabelBatch::sole();
    $batch->refresh();
    $page->assertPathIs('/batch-shipments/'.$batch->id);

    expect($batch->status)->toBe(LabelBatchStatus::Completed)
        ->and($batch->successful_shipments)->toBe(2)
        ->and(Package::where('status', PackageStatus::Shipped)->count())->toBe(2);

    // Print the batch through a recording QZ Tray.
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

    $page->click('button:has-text("Print Labels (2)")');
    $page->wait(3);

    expect($page->script('window.printed'))->toBe(2)
        ->and(Package::whereNull('label_printed_at')->count())->toBe(0);

    $page->assertNoJavaScriptErrors();
});
