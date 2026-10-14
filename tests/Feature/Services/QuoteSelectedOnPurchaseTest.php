<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\RateQuote;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Services\Carriers\CarrierRegistry;

beforeEach(fn () => app(CarrierRegistry::class)->reset());
afterEach(fn () => app(CarrierRegistry::class)->reset());

/**
 * A quoted Package, its quote row, and an Offer pointing at that row.
 *
 * @return array{Package, ShippingOffer, int, RateResponse}
 */
function quotedPackageWithOffer(): array
{
    $product = Product::factory()->create(['weight' => 1.5]);
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id, 'name' => 'Ground', 'service_code' => 'GROUND', 'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $item = ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $product->id, 'quantity' => 1]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0, 'height' => 10, 'width' => 8, 'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);
    $package->packageItems()->create(['shipment_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 1]);
    $package = $package->fresh();

    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');
    $quoteId = RateQuote::query()->insertGetId([
        'package_id' => $package->id, 'carrier' => 'MockCarrier', 'service_code' => 'GROUND',
        'service_name' => 'Ground', 'quoted_price' => 7.25, 'selected' => false, 'created_at' => now(),
    ]);
    $offer = ShippingOffer::factory()->direct()->forRate($rate)
        ->quotedFor(Package::with('shipment')->find($package->id))
        ->create(['rate_quote_id' => $quoteId]);

    return [$package, $offer, $quoteId, $rate];
}

function mockCarrierAnswering(ShipResponse $response): void
{
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn($response);
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

it('does not mark a quote selected when the carrier declines the purchase', function (): void {
    [$package, $offer, $quoteId, $rate] = quotedPackageWithOffer();
    mockCarrierAnswering(ShipResponse::failure('Rate unavailable for this destination.'));

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: RateResponse::fromArray($rate->withOfferId($offer->public_id)->toArray())),
    );

    expect($result->success)->toBeFalse()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and(RateQuote::find($quoteId)->selected)->toBeFalse();
});

it('marks the quote selected once the label is recorded', function (): void {
    [$package, $offer, $quoteId, $rate] = quotedPackageWithOffer();
    mockCarrierAnswering(ShipResponse::success(
        trackingNumber: '1Z0000000000000001', cost: 7.25, carrier: 'MockCarrier', service: 'Ground',
        labelData: base64_encode('LABEL'), labelFormat: 'pdf',
    ));

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: RateResponse::fromArray($rate->withOfferId($offer->public_id)->toArray())),
    );

    expect($result->success)->toBeTrue()
        ->and(RateQuote::find($quoteId)->selected)->toBeTrue();
});
