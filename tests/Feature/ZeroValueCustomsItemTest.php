<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Models\Location;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\Carriers\CarrierRegistry;

/**
 * Issue `26`: a customs line worth `$0.00` is either refused by the carrier
 * after the box is closed or printed as an understated declaration. Neither
 * reaches the carrier; the operator is told which item and sent to fix it at
 * source.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A packed box with one line per value given. An FPO address is domestic but
 * customs-declared, which is the cheapest way onto the customs path; pass a
 * stateside address to stay off it.
 *
 * @param  list<float|null>  $values  One shipment item per entry, in order
 * @param  array<string, mixed>  $address
 */
function packageWithItemValues(array $values, array $address = [], ?Location $from = null): Package
{
    $shipment = Shipment::factory()->create($address + [
        'city' => 'FPO',
        'state_or_province' => 'AE',
        'postal_code' => '09532',
        'country' => 'US',
    ]);

    $package = Package::factory()->for($shipment)->create(array_filter([
        'weight' => 2.0,
        'status' => PackageStatus::Unshipped,
        'location_id' => $from?->id,
    ]));

    foreach ($values as $index => $value) {
        // Named as the customs form would name it, which is what the refusal quotes.
        $product = Product::factory()->create(['description' => 'Item '.($index + 1), 'weight' => 0.2]);
        $shipmentItem = ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'value' => $value,
        ]);
        $package->packageItems()->create([
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    return $package;
}

function carrierThatMustNotBeAsked(): void
{
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

function carrierThatSells(): void
{
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('createShipment')->once()->andReturn(ShipResponse::success(
        trackingNumber: 'TRACK123',
        cost: 7.25,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

function mockCarrierRate(): RateResponse
{
    return new RateResponse(carrier: 'MockCarrier', serviceCode: 'GROUND', serviceName: 'Ground', price: 7.25);
}

it('withholds a purchase whose customs items are all worth nothing', function (): void {
    $package = packageWithItemValues([0.0, 0.0]);
    carrierThatMustNotBeAsked();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: mockCarrierRate()),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Customs Value Required')
        ->and($result->message)->toContain('2 items have no value: Item 1, Item 2')
        ->and($result->message)->not->toContain('120502')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('withholds a purchase with one free line among paid ones rather than passing it on a positive sum', function (): void {
    $package = packageWithItemValues([12.50, 0.0, 30.00]);
    carrierThatMustNotBeAsked();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: mockCarrierRate()),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Customs Value Required')
        ->and($result->message)->toContain('one item has no value: Item 2')
        ->and($result->message)->not->toContain('Item 1')
        ->and($result->message)->not->toContain('Item 3');
});

it('refuses before asking the operator to confirm a customs weight', function (): void {
    // Customs weight 0.2 lb per unit over a 0.1 lb box would otherwise prompt.
    $package = packageWithItemValues([0.0]);
    $package->update(['weight' => 0.1]);
    carrierThatMustNotBeAsked();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: mockCarrierRate(), requireCustomsWeightOverride: true),
    );

    expect($result->requiresCustomsWeightOverride)->toBeFalse()
        ->and($result->title)->toBe('Customs Value Required');
});

it('still falls back to a value of 1 for a null item value, which predates the guard', function (): void {
    $package = packageWithItemValues([null]);
    carrierThatSells();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: mockCarrierRate()),
    );

    expect($result->success)->toBeTrue();

    $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem']);
    expect(CustomsItem::fromPackageItem($package->packageItems->first())->unitValue)->toBe(1.0);
});

it('lets a zero-value item ship domestically, where no declaration is made', function (): void {
    $package = packageWithItemValues([0.0], [
        'city' => 'Portland',
        'state_or_province' => 'OR',
        'postal_code' => '97201',
        'country' => 'US',
    ]);
    carrierThatSells();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: mockCarrierRate()),
    );

    expect($result->success)->toBeTrue();
});

it('does not apply to a blind purchase, whose seller declares from its own catalogue', function (): void {
    $package = packageWithItemValues([0.0]);

    $request = ShipRequest::fromPackageAndBlindOffer($package, new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Shopify Shipping',
        serviceCode: 'auto',
        selectionLabel: "Shopify's choice",
    ));

    expect($request->customsItems)->toHaveCount(1)
        ->and($request->customsItems[0]->unitValue)->toBe(0.0)
        ->and($request->zeroValueCustomsItems())->toBe([]);
});

/**
 * Customs is a question about the pair of addresses. A Canadian origin makes a
 * Canadian destination domestic and a US one international, the reverse of
 * what the destination alone would say.
 */
function canadianLocation(): Location
{
    return Location::factory()->create([
        'city' => 'Toronto',
        'state_or_province' => 'ON',
        'postal_code' => 'M5V 3L9',
        'country' => 'CA',
    ]);
}

it('lets a zero-value item ship within the origin country, even when that is not the US', function (): void {
    $package = packageWithItemValues([0.0], [
        'city' => 'Vancouver',
        'state_or_province' => 'BC',
        'postal_code' => 'V6B 1A1',
        'country' => 'CA',
    ], canadianLocation());
    carrierThatSells();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: mockCarrierRate()),
    );

    expect($result->success)->toBeTrue();
});

it('withholds a zero-value item shipped into the US from abroad, which the destination alone would let through', function (): void {
    $package = packageWithItemValues([0.0], [
        'city' => 'Portland',
        'state_or_province' => 'OR',
        'postal_code' => '97201',
        'country' => 'US',
    ], canadianLocation());
    carrierThatMustNotBeAsked();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: mockCarrierRate()),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Customs Value Required');
});
