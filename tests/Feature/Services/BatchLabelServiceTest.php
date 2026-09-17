<?php

use App\Contracts\PostageOfferSource;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\LabelBatchItemStatus;
use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Enums\PickingStatus;
use App\Jobs\GenerateLabelJob;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\BatchLabelService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    $this->service = new BatchLabelService;
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

// --- validateShipmentsForBatch ---

it('marks already-shipped shipments as ineligible', function (): void {
    $shipment = Shipment::factory()->shipped()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->eligible)->toBeEmpty()
        ->and($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Already shipped');
});

it('marks Amazon-fulfilled (FBA) shipments as ineligible', function (): void {
    $shipment = Shipment::factory()->create([
        'metadata' => ['amazon_fulfilled_by' => 'AMAZON'],
    ]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->eligible)->toBeEmpty()
        ->and($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Fulfilled by Amazon (FBA)');
});

it('marks shipments without shipping method as ineligible', function (): void {
    $shipment = Shipment::factory()->withoutShippingMethod()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('No shipping method assigned');
});

it('marks shipments with missing address fields as ineligible', function (): void {
    $shipment = Shipment::factory()->create(['address1' => null]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Missing address fields');
});

it('marks shipments with existing unshipped packages as ineligible', function (): void {
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
    Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Has existing unshipped packages');
});

it('marks shipments with zero-weight products as ineligible', function (): void {
    $product = Product::factory()->create(['weight' => 0]);
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toContain('Item missing product weight');
});

it('marks shipments with transparency-required items as ineligible', function (): void {
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->withTransparency()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Contains transparency-required items');
});

it('marks unpicked shipments as ineligible when picking is required before shipping', function (): void {
    app(SettingsService::class)->set('picking_enabled', true);
    app(SettingsService::class)->set('require_picking_before_shipping', true);

    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->ineligible)->toHaveCount(1)
        ->and($result->ineligible->first()['reason'])->toBe('Not picked');
});

it('marks picked shipments as eligible when picking is required before shipping', function (): void {
    app(SettingsService::class)->set('picking_enabled', true);
    app(SettingsService::class)->set('require_picking_before_shipping', true);

    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Picked]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->eligible)->toHaveCount(1)
        ->and($result->ineligible)->toBeEmpty();
});

it('does not require picking when the require setting is disabled', function (): void {
    app(SettingsService::class)->set('picking_enabled', true);
    app(SettingsService::class)->set('require_picking_before_shipping', false);

    $shipment = Shipment::factory()->create(['picking_status' => PickingStatus::Pending]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]));

    expect($result->eligible)->toHaveCount(1)
        ->and($result->ineligible)->toBeEmpty();
});

it('correctly separates eligible and ineligible shipments', function (): void {
    $eligible = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $eligible->id]);

    $ineligible = Shipment::factory()->shipped()->create();
    ShipmentItem::factory()->create(['shipment_id' => $ineligible->id]);

    $result = $this->service->validateShipmentsForBatch(collect([$eligible, $ineligible]));

    expect($result->eligible)->toHaveCount(1)
        ->and($result->ineligible)->toHaveCount(1)
        ->and($result->hasIneligible())->toBeTrue()
        ->and($result->allIneligible())->toBeFalse();
});

it('marks all eligible when all shipments are valid', function (): void {
    $shipments = collect();
    for ($i = 0; $i < 3; $i++) {
        $s = Shipment::factory()->create();
        ShipmentItem::factory()->create(['shipment_id' => $s->id]);
        $shipments->push($s);
    }

    $result = $this->service->validateShipmentsForBatch($shipments);

    expect($result->eligible)->toHaveCount(3)
        ->and($result->ineligible)->toBeEmpty()
        ->and($result->hasIneligible())->toBeFalse();
});

// --- createBatch ---

it('creates packages with correct weight calculation', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create(['empty_weight' => 0.50, 'height' => 4, 'width' => 6, 'length' => 8]);

    $product1 = Product::factory()->create(['weight' => 1.00]);
    $product2 = Product::factory()->create(['weight' => 0.50]);

    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product1->id, 'quantity' => 2]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product2->id, 'quantity' => 1]);

    // Reload with relations for createBatch
    $shipment->load('shipmentItems.product');

    $batch = $this->service->createBatch(collect([$shipment]), $boxSize, $user, 'pdf', null);

    expect($batch)->toBeInstanceOf(LabelBatch::class)
        ->and($batch->total_shipments)->toBe(1)
        ->and($batch->status)->toBe(LabelBatchStatus::Pending);

    $package = Package::where('shipment_id', $shipment->id)->first();
    // 0.50 (box) + 2*1.00 (product1) + 1*0.50 (product2) = 3.00
    expect((float) $package->weight)->toBe(3.00)
        ->and((float) $package->height)->toBe(4.00)
        ->and((float) $package->width)->toBe(6.00)
        ->and((float) $package->length)->toBe(8.00);
});

it('creates package items from shipment items', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create();

    $shipment = Shipment::factory()->create();
    $item1 = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 3]);
    $item2 = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);

    $shipment->load('shipmentItems.product');

    $batch = $this->service->createBatch(collect([$shipment]), $boxSize, $user, 'pdf', null);

    $package = Package::where('shipment_id', $shipment->id)->first();
    $packageItems = PackageItem::where('package_id', $package->id)->get();

    expect($packageItems)->toHaveCount(2);
    expect($packageItems->firstWhere('shipment_item_id', $item1->id)->quantity)->toBe(3);
    expect($packageItems->firstWhere('shipment_item_id', $item2->id)->quantity)->toBe(1);
});

it('creates label batch items for each shipment', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create();

    $shipments = collect();
    for ($i = 0; $i < 3; $i++) {
        $s = Shipment::factory()->create();
        ShipmentItem::factory()->create(['shipment_id' => $s->id]);
        $s->load('shipmentItems.product');
        $shipments->push($s);
    }

    $batch = $this->service->createBatch($shipments, $boxSize, $user, 'pdf', null);

    $batchItems = LabelBatchItem::where('label_batch_id', $batch->id)->get();

    expect($batchItems)->toHaveCount(3);
    expect($batchItems->pluck('status')->unique()->toArray())->toBe([LabelBatchItemStatus::Pending]);
});

it('dispatches a bus batch with jobs', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create();

    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
    $shipment->load('shipmentItems.product');

    $batch = $this->service->createBatch(collect([$shipment]), $boxSize, $user, 'zpl', 203);

    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 1;
    });

    expect($batch->label_format)->toBe('zpl')
        ->and($batch->label_dpi)->toBe(203);
});

// --- the report printer skip (shopify-shipping-carrier/07 constraint 4) ---

/**
 * An international shipment on a method that can buy from the named carriers,
 * each registered as a fake that answers the report printer gate as told.
 *
 * @param  array<string, CustomsDocumentDelivery>  $carriers
 */
function internationalShipmentOnCarriers(array $carriers): Shipment
{
    $method = ShippingMethod::factory()->create();

    foreach ($carriers as $name => $delivery) {
        $carrier = Carrier::factory()->create(['name' => $name, 'active' => true]);
        $service = CarrierService::factory()->create(['carrier_id' => $carrier->id, 'active' => true]);
        $method->carrierServices()->attach($service->id);

        app(CarrierRegistry::class)->registerInstance($name, new FakeCarrierAdapter($name, $delivery));
    }

    $shipment = Shipment::factory()->create([
        'shipping_method_id' => $method->id,
        'address1' => '100 Queen St W',
        'city' => 'Toronto',
        'state_or_province' => 'ON',
        'postal_code' => 'M5H 2N2',
        'country' => 'CA',
    ]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);

    return $shipment;
}

it('skips an international shipment when every carrier on its method returns a separate customs form and there is no report printer', function (): void {
    $shipment = internationalShipmentOnCarriers(['UPS' => CustomsDocumentDelivery::Separate]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]), hasReportPrinter: false);

    expect($result->eligible)->toBeEmpty()
        ->and($result->ineligible->first()['reason'])->toBe('No document printer configured for the customs form');
});

it('keeps an international shipment when one carrier on its method fuses the customs form into the label', function (): void {
    // The batch has not chosen a carrier yet, and USPS's CP72 prints on the
    // label printer, so a method offering USPS beside UPS can still buy.
    $shipment = internationalShipmentOnCarriers([
        'UPS' => CustomsDocumentDelivery::Separate,
        'USPS' => CustomsDocumentDelivery::FusedIntoLabel,
    ]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]), hasReportPrinter: false);

    expect($result->eligible)->toHaveCount(1)
        ->and($result->ineligible)->toBeEmpty();
});

it('does not let an unconfigured fused-document carrier rescue the shipment from the skip', function (): void {
    // Rate shopping never asks an unconfigured adapter, so it is not a way the
    // batch could buy: the only carrier that can quote needs a report printer.
    $shipment = internationalShipmentOnCarriers(['UPS' => CustomsDocumentDelivery::Separate]);

    $usps = Carrier::factory()->create(['name' => 'USPS', 'active' => true]);
    $shipment->shippingMethod->carrierServices()->attach(
        CarrierService::factory()->create(['carrier_id' => $usps->id, 'active' => true])->id,
    );
    $unconfigured = Mockery::mock(PostageOfferSource::class);
    $unconfigured->shouldReceive('isConfigured')->andReturnFalse();
    $unconfigured->shouldNotReceive('customsDocumentDelivery');
    app(CarrierRegistry::class)->registerInstance('USPS', $unconfigured);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]), hasReportPrinter: false);

    expect($result->ineligible->first()['reason'])->toBe('No document printer configured for the customs form');
});

it('does not let a fused-document carrier that cannot reach the destination rescue the shipment from the skip', function (): void {
    // The same destination-capability filter rate shopping applies: a
    // military address is customs-declared, and a USPS service flagged unable
    // to reach it is not offered, leaving UPS alone.
    $shipment = internationalShipmentOnCarriers([
        'UPS' => CustomsDocumentDelivery::Separate,
        'USPS' => CustomsDocumentDelivery::FusedIntoLabel,
    ]);
    $shipment->update(['city' => 'FPO', 'state_or_province' => 'AE', 'postal_code' => '09532', 'country' => 'US']);
    CarrierService::query()->update(['can_ship_to_military_addresses' => true]);
    CarrierService::whereHas('carrier', fn ($q) => $q->where('name', 'USPS'))->update(['can_ship_to_military_addresses' => false]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]), hasReportPrinter: false);

    expect($result->ineligible->first()['reason'])->toBe('No document printer configured for the customs form');
});

it('keeps an international shipment on a separate-document carrier once a report printer is configured', function (): void {
    $shipment = internationalShipmentOnCarriers(['UPS' => CustomsDocumentDelivery::Separate]);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]), hasReportPrinter: true);

    expect($result->eligible)->toHaveCount(1);
});

it('never asks about a report printer for a domestic shipment', function (): void {
    $shipment = internationalShipmentOnCarriers(['UPS' => CustomsDocumentDelivery::Separate]);
    $shipment->update(['city' => 'Portland', 'state_or_province' => 'OR', 'postal_code' => '97201', 'country' => 'US']);

    $result = $this->service->validateShipmentsForBatch(collect([$shipment]), hasReportPrinter: false);

    expect($result->eligible)->toHaveCount(1);
});

it('hands the report printer flag to every label job in the batch', function (): void {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $boxSize = BoxSize::factory()->create();

    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id]);
    $shipment->load('shipmentItems.product');

    $this->service->createBatch(collect([$shipment]), $boxSize, $user, 'pdf', null, hasReportPrinter: true);

    Bus::assertBatched(fn ($batch): bool => $batch->jobs->every(fn (GenerateLabelJob $job): bool => $job->hasReportPrinter));
});
