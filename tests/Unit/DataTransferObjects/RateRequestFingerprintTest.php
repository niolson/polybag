<?php

use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use Carbon\CarbonImmutable;

/**
 * `RateRequest::fingerprint()` — what a direct-carrier offer is bound to.
 *
 * @param  array<string, mixed>  $overrides
 */
function rateRequestFor(array $overrides = []): RateRequest
{
    return new RateRequest(...[
        'originPostalCode' => '98072',
        'destinationPostalCode' => '90210',
        'destinationCity' => 'Beverly Hills',
        'destinationStateOrProvince' => 'CA',
        'destinationStreetAddress' => '123 Palm Drive',
        'destinationStreetAddress2' => 'Suite 4',
        'residential' => true,
        'packages' => [new PackageData(weight: 2.0, length: 10, width: 8, height: 6, boxType: BoxSizeType::BOX)],
        'specialServiceCodes' => ['signature_required', 'declared_value'],
        'specialServiceConfig' => ['declared_value' => ['amount' => 40.0, 'currency' => 'USD']],
        'locationId' => 3,
        'clientId' => 7,
        'contentsValue' => 40.0,
        'packageId' => 11,
        'shippingMethodId' => 2,
        ...$overrides,
    ]);
}

it('digests to 64 hex characters', function (): void {
    expect(rateRequestFor()->fingerprint())->toMatch('/^[0-9a-f]{64}$/');
});

it('builds a quote destination from server address and classification state', function (): void {
    $location = Location::factory()->default()->create();
    $shipment = Shipment::factory()->create([
        'address1' => '1 Imported Street',
        'address2' => 'Unit 2',
        'city' => 'Imported City',
        'residential' => true,
        'validated_address1' => '9 Validated Avenue',
        'validated_address2' => null,
        'validated_city' => 'Validated City',
        'validated_residential' => false,
    ]);
    $package = Package::factory()->for($shipment)->for($location)->create();

    $request = RateRequest::fromPackage($package);

    expect($request->destinationStreetAddress)->toBe('9 Validated Avenue')
        ->and($request->destinationStreetAddress2)->toBe('Unit 2')
        ->and($request->destinationCity)->toBe('Validated City')
        ->and($request->residential)->toBeFalse();
});

it('is stable across the order codes and config keys were given in', function (): void {
    $one = rateRequestFor([
        'specialServiceCodes' => ['signature_required', 'declared_value'],
        'specialServiceConfig' => ['declared_value' => ['amount' => 40.0, 'currency' => 'USD']],
    ]);
    $other = rateRequestFor([
        'specialServiceCodes' => ['declared_value', 'signature_required'],
        'specialServiceConfig' => ['declared_value' => ['currency' => 'USD', 'amount' => 40.0]],
    ]);

    expect($one->fingerprint())->toBe($other->fingerprint());
});

it('ignores the ship date, which is the window and not the identity', function (): void {
    $quoted = rateRequestFor();

    expect($quoted->withShipDate(CarbonImmutable::parse('2026-09-18'))->fingerprint())->toBe($quoted->fingerprint())
        ->and($quoted->withShipDate(CarbonImmutable::parse('2026-09-21'))->fingerprint())->toBe($quoted->fingerprint());
});

it('ignores the package id, which the offer row already binds', function (): void {
    expect(rateRequestFor(['packageId' => 11])->fingerprint())
        ->toBe(rateRequestFor(['packageId' => 12])->fingerprint());
});

it('changes with anything the carrier was asked to price', function (): void {
    $base = rateRequestFor()->fingerprint();

    expect(rateRequestFor(['destinationPostalCode' => '99501'])->fingerprint())->not->toBe($base)
        ->and(rateRequestFor(['destinationStreetAddress' => '125 Palm Drive'])->fingerprint())->not->toBe($base)
        ->and(rateRequestFor(['destinationStreetAddress2' => null])->fingerprint())->not->toBe($base)
        ->and(rateRequestFor(['residential' => false])->fingerprint())->not->toBe($base)
        ->and(rateRequestFor(['packages' => [new PackageData(weight: 3.0, length: 10, width: 8, height: 6, boxType: BoxSizeType::BOX)]])->fingerprint())->not->toBe($base)
        ->and(rateRequestFor(['packages' => [new PackageData(weight: 2.0, length: 10, width: 8, height: 6, boxType: BoxSizeType::BOX, carrierPackaging: CarrierPackaging::UspsMediumFlatRateBox)]])->fingerprint())->not->toBe($base)
        ->and(rateRequestFor(['specialServiceCodes' => ['declared_value']])->fingerprint())->not->toBe($base)
        ->and(rateRequestFor(['specialServiceConfig' => ['declared_value' => ['amount' => 41.0, 'currency' => 'USD']]])->fingerprint())->not->toBe($base)
        ->and(rateRequestFor(['clientId' => 8])->fingerprint())->not->toBe($base);
});

it('changes with the shipping method, which decides who is asked at all', function (): void {
    // No carrier prices on the method, but the method decides which carriers
    // and services are on the list: a swap changes the list the price is on.
    expect(rateRequestFor(['shippingMethodId' => 3])->fingerprint())
        ->not->toBe(rateRequestFor(['shippingMethodId' => 2])->fingerprint());
});

it('stripping a special service digests the same as never having had it', function (): void {
    expect(rateRequestFor()->withoutSpecialService('signature_required')->fingerprint())
        ->toBe(rateRequestFor(['specialServiceCodes' => ['declared_value']])->fingerprint());
});
