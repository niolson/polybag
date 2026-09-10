<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;

/**
 * Total weight a carrier declares for these commodities, computed the way both
 * the FedEx and UPS adapters do it — per line, rounded to the hundredth.
 *
 * @param  array<int, CustomsItem>  $items
 */
function declaredCustomsWeight(array $items): float
{
    return round(collect($items)->sum(fn (CustomsItem $item): float => round($item->weight * $item->quantity, 2)), 2);
}

/**
 * @param  array<int, CustomsItem>  $customsItems
 */
function shipRequestWeighing(float $packageWeight, array $customsItems): ShipRequest
{
    $address = new AddressData(
        firstName: 'Test',
        lastName: 'Recipient',
        streetAddress: '1 Test Street',
        city: 'Toronto',
        stateOrProvince: 'ON',
        postalCode: 'M5H 2N2',
        country: 'CA',
    );

    return new ShipRequest(
        fromAddress: $address,
        toAddress: $address,
        packageData: new PackageData(weight: $packageWeight, length: 6, width: 6, height: 4),
        selectedRate: new RateResponse('FedEx', 'FEDEX_INTERNATIONAL_CONNECT_PLUS', 'Connect Plus', 33.40),
        customsItems: $customsItems,
    );
}

it('keeps the declared customs weight inside the package weight when a unit weight would round up', function (): void {
    // The package FedEx rejected: 26.02 lb of catalog weight declared against a
    // 0.15 lb box. Rounding each unit weight gave 0.06 and 0.02 a unit, which
    // the quantity of 2 turned into 0.12 + 0.04 = 0.16 — over the box.
    $request = shipRequestWeighing(0.15, [
        new CustomsItem(description: 'The Complete Snowboard', quantity: 2, unitValue: 699.95, weight: 10.0),
        new CustomsItem(description: 'Trail & Table Nylon Leash', quantity: 2, unitValue: 49.44, weight: 3.01),
    ]);

    $scaled = $request->withScaledCustomsWeights();

    expect(declaredCustomsWeight($scaled->customsItems))->toBeLessThanOrEqual(0.15)
        ->and($scaled->customsItems[0]->weight)->toBe(0.05)
        ->and($scaled->customsItems[1]->weight)->toBe(0.01);
});

it('never declares a commodity at zero weight', function (): void {
    $request = shipRequestWeighing(0.15, [
        new CustomsItem(description: 'Heavy', quantity: 1, unitValue: 10.0, weight: 500.0),
        new CustomsItem(description: 'Featherweight', quantity: 1, unitValue: 1.0, weight: 0.001),
    ]);

    foreach ($request->withScaledCustomsWeights()->customsItems as $item) {
        expect($item->weight)->toBeGreaterThan(0.0);
    }
});

it('leaves a declaration that already fits the package alone', function (): void {
    $items = [
        new CustomsItem(description: 'Mug', quantity: 2, unitValue: 12.0, weight: 0.4),
    ];

    $request = shipRequestWeighing(2.0, $items);

    expect($request->withScaledCustomsWeights()->customsItems[0]->weight)->toBe(0.4);
});

it('keeps the declaration inside the package weight across awkward scaling factors', function (
    float $packageWeight,
    array $unitWeights,
    int $quantity,
): void {
    $items = array_map(
        fn (float $weight, int $index): CustomsItem => new CustomsItem(
            description: "Item {$index}",
            quantity: $quantity,
            unitValue: 10.0,
            weight: $weight,
        ),
        $unitWeights,
        array_keys($unitWeights),
    );

    $scaled = shipRequestWeighing($packageWeight, $items)->withScaledCustomsWeights();

    expect(declaredCustomsWeight($scaled->customsItems))->toBeLessThanOrEqual($packageWeight);
})->with([
    'thirds of a pound' => [2.0, [1.0, 1.0, 1.0], 1],
    'quantity multiplies the shortfall' => [2.0, [1.0, 1.0, 1.0], 3],
    'a heavy catalog in a light box' => [0.15, [10.0, 3.01], 2],
    'many small items' => [1.0, [0.7, 0.7, 0.7, 0.7, 0.7], 2],
    'one item far too heavy' => [0.5, [12.5], 4],
    'binary-unfriendly scale' => [0.29, [1.1, 2.2, 3.3], 3],
]);
