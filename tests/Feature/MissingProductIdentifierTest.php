<?php

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\ShippingRuleAction;
use App\Filament\Pages\Ship;
use App\Jobs\GenerateLabelJob;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * `eu-product-identifiers/04`: from 1 November 2026 EU customs holds a
 * consumer parcel whose lines lack the merchant or manufacturer identifier,
 * while the carriers go on selling the label. Neither reaches the carrier; the
 * operator is told which product and sent to the product form.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A shipping method whose one service, on the mock carrier, a *Use* rule
 * selects — what both the Ship page and an unattended purchase need.
 */
function mockCarrierMethod(): ShippingMethod
{
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Test Service',
        'service_code' => 'TEST',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);
    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    return $method;
}

/**
 * A packed box bound for a consumer in Paris, one line per product given.
 *
 * @param  list<array<string, mixed>>  $products  Product attributes, one line each
 */
function packageToFranceHolding(array $products, ShippingMethod $method): Package
{
    $shipment = Shipment::factory()->create([
        'company' => null,
        'city' => 'Paris',
        'state_or_province' => null,
        'postal_code' => '75001',
        'country' => 'FR',
        'shipping_method_id' => $method->id,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'status' => PackageStatus::Unshipped,
    ]);

    foreach ($products as $attributes) {
        $product = Product::factory()->create($attributes + ['weight' => 0.2]);
        $shipmentItem = ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'value' => 12.0,
            'transparency' => false,
        ]);
        $package->packageItems()->create([
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    return $package;
}

/**
 * A direct carrier that quotes the given service and, where a test lets it,
 * sells. Fused customs documents, so the report printer gate stands aside
 * and the identifier guard is what refuses.
 */
function identifierGuardCarrier(int $sales = 0): MockInterface
{
    $carrierServiceId = CarrierService::query()->where('service_code', 'TEST')->value('id');

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::FusedIntoLabel);
    $adapter->shouldReceive('getCarrierName')->andReturn('MockCarrier');
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse('MockCarrier', 'TEST', 'Test Service', 7.50, carrierServiceId: $carrierServiceId),
    ]));

    if ($sales === 0) {
        $adapter->shouldNotReceive('createShipment');
    } else {
        $adapter->shouldReceive('createShipment')->times($sales)->andReturnUsing(fn (): ShipResponse => ShipResponse::success(
            trackingNumber: 'TRACK'.random_int(1000, 9999),
            cost: 7.50,
            carrier: 'MockCarrier',
            service: 'Test Service',
            labelData: base64_encode('label'),
        ));
    }

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    return $adapter;
}

/**
 * The Ship page for the package with one quoted rate selected, and the offer
 * behind it.
 *
 * @return array{0: Testable, 1: ShippingOffer}
 */
function shipPageWithQuotedRate(Package $package): array
{
    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);
    $rate = quotedDirectly($package, new RateResponse('MockCarrier', 'TEST', 'Test Service', 7.50));

    $component->set('rateOptions', [0 => $rate->toArray()]);
    $component->set('formRateOptionDescriptions', [0 => '$7.50 - Test Service']);
    $component->set('selectedRateIndex', 0);

    return [$component, ShippingOffer::query()->where('public_id', $rate->offerId)->firstOrFail()];
}

/**
 * The body of every notification sent with the given title, read the way
 * Filament's own assertNotified() reads them — which consumes them, so it is
 * asked once per test.
 *
 * @return list<string>
 */
function notificationBodiesTitled(string $title): array
{
    $notifications = new Notifications;
    $notifications->mount();

    return $notifications->notifications
        ->filter(fn (Notification $notification): bool => $notification->getTitle() === $title)
        ->map(fn (Notification $notification): string => (string) $notification->getBody())
        ->values()
        ->all();
}

it('refuses on the Ship page, naming the product by SKU and what it lacks, and leaves the offer unclaimed', function (): void {
    $package = packageToFranceHolding([
        ['sku' => 'MUG-001', 'manufacturer_part_number' => null, 'description' => 'Ceramic Mug'],
        ['sku' => 'TEA-002', 'manufacturer_part_number' => 'MFG-2', 'description' => 'Loose Tea'],
    ], mockCarrierMethod());
    identifierGuardCarrier();

    [$component, $offer] = shipPageWithQuotedRate($package);

    $component->call('ship')->assertNotDispatched('print-label');
    $bodies = notificationBodiesTitled('Product Identifier Required');

    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->toContain('one item is missing')
        ->and($bodies[0])->toContain('MUG-001 (no Manufacturer Part Number)')
        ->and($bodies[0])->not->toContain('TEA-002')
        ->and($bodies[0])->toContain('product form')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($offer->fresh()->isConsumed())->toBeFalse();
});

it('names a product with no SKU by its description', function (): void {
    $package = packageToFranceHolding([
        ['sku' => '', 'manufacturer_part_number' => null, 'description' => 'Ceramic Mug'],
    ], mockCarrierMethod());
    identifierGuardCarrier();

    [$component] = shipPageWithQuotedRate($package);

    $component->call('ship');
    $bodies = notificationBodiesTitled('Product Identifier Required');

    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->toContain('Ceramic Mug (no SKU or Manufacturer Part Number)');
});

it('lets a business consignee in the EU through without the identifiers', function (): void {
    $package = packageToFranceHolding([
        ['sku' => 'MUG-001', 'manufacturer_part_number' => null],
    ], mockCarrierMethod());
    $package->shipment->update(['company' => 'Maison Martin SARL']);
    identifierGuardCarrier(sales: 1);

    [$component] = shipPageWithQuotedRate($package);

    $component->call('ship');

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('records the refusal against the package in a batch and goes on to the next', function (): void {
    $user = User::factory()->admin()->create();
    $method = mockCarrierMethod();
    identifierGuardCarrier(sales: 1);

    $refused = packageToFranceHolding([['sku' => 'MUG-001', 'manufacturer_part_number' => null]], $method);
    $complete = packageToFranceHolding([['sku' => 'TEA-002', 'manufacturer_part_number' => 'MFG-2']], $method);

    $batch = LabelBatch::factory()->processing()->create(['user_id' => $user->id, 'total_shipments' => 2]);
    $items = collect([$refused, $complete])->map(fn (Package $package): LabelBatchItem => LabelBatchItem::factory()->create([
        'label_batch_id' => $batch->id,
        'shipment_id' => $package->shipment_id,
        'package_id' => $package->id,
    ]));

    $items->each(fn (LabelBatchItem $item) => (new GenerateLabelJob($item->id, 'pdf', null))->handle());

    [$refusedItem, $completeItem] = $items->map->fresh()->all();

    expect($refusedItem->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($refusedItem->error_message)->toContain('MUG-001 (no Manufacturer Part Number)')
        ->and($completeItem->status)->toBe(LabelBatchItemStatus::Success)
        ->and($batch->fresh()->failed_shipments)->toBe(1)
        ->and($batch->fresh()->successful_shipments)->toBe(1);
});
