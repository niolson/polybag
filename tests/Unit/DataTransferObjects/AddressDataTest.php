<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\Models\Shipment;

it('resolves validated, imported, and unknown residential classifications', function (): void {
    $validatedCommercial = Shipment::factory()->create([
        'residential' => true,
        'validated_residential' => false,
    ]);
    $importedCommercial = Shipment::factory()->create([
        'residential' => false,
        'validated_residential' => null,
    ]);
    $unknown = Shipment::factory()->create([
        'residential' => null,
        'validated_residential' => null,
    ]);

    expect(AddressData::fromShipment($validatedCommercial)->residential)->toBeFalse()
        ->and(AddressData::fromShipment($validatedCommercial)->isResidential())->toBeFalse()
        ->and(AddressData::fromShipment($importedCommercial)->residential)->toBeFalse()
        ->and(AddressData::fromShipment($importedCommercial)->isResidential())->toBeFalse()
        ->and(AddressData::fromShipment($unknown)->residential)->toBeNull()
        ->and(AddressData::fromShipment($unknown)->isResidential())->toBeTrue();
});

function makeAddress(string $streetAddress, ?string $streetAddress2 = null, ?string $uspsCarrierRoute = null, string $country = 'US'): AddressData
{
    return new AddressData(
        firstName: 'Jane',
        lastName: 'Doe',
        streetAddress: $streetAddress,
        city: 'Springfield',
        stateOrProvince: 'IL',
        postalCode: '62704',
        country: $country,
        streetAddress2: $streetAddress2,
        uspsCarrierRoute: $uspsCarrierRoute,
    );
}

it('detects standard PO Box formats', function (string $line): void {
    expect(makeAddress($line)->isPoBox())->toBeTrue();
})->with([
    'PO Box 411',
    'P.O. Box 1142',
    'Pobox 1982',
    'PO Box 26384',
    'POB 711',
    '6873 N Ridge Rd Box186',
]);

it('detects rural route, highway contract, and general delivery box formats', function (string $line): void {
    expect(makeAddress($line)->isPoBox())->toBeTrue();
})->with([
    'RR 1 Box 42108',
    'HC 71 Box 21',
    'Star Route Box 12',
    'GENERAL DELIVERY',
]);

it('detects letter-prefixed box numbers and "Box No." phrasing', function (): void {
    expect(makeAddress('Rt 1 Box A11')->isPoBox())->toBeTrue()
        ->and(makeAddress('263 Alden St', 'Box No. 2544')->isPoBox())->toBeTrue()
        ->and(makeAddress('PO BOX B')->isPoBox())->toBeTrue();
});

it('does not flag street names that merely contain "box"', function (string $line): void {
    expect(makeAddress($line)->isPoBox())->toBeFalse();
})->with([
    '535 Boxwood Dr',
    '762 Box Canyon Ct',
    '7205 Box Car Ct',
    '5774 Box Elder Rd',
    '210 Box Ln',
]);

it('does not flag property-access lockbox delivery notes on real street addresses', function (): void {
    $address = makeAddress('118 Ponderosa Court', 'CAN USE LOCK BOX NUMBER 0529#');

    expect($address->isPoBox())->toBeFalse();
});

it('checks the second address line too', function (): void {
    $address = makeAddress('123 Warehouse Way', 'PO Box 42');

    expect($address->isPoBox())->toBeTrue();
});

it('is never a PO Box outside the US', function (): void {
    $address = makeAddress('PO Box 42', country: 'CA');

    expect($address->isPoBox())->toBeFalse();
});

it('prefers the USPS carrier route over the regex when available', function (): void {
    // Carrier route "B..." is a dedicated PO Box route -- trusted even though
    // the street text alone wouldn't match the regex.
    $poBoxRoute = makeAddress('123 Warehouse Way', uspsCarrierRoute: 'B012');
    expect($poBoxRoute->isPoBox())->toBeTrue();

    // A city route means USPS confirmed this is a real street address, so it
    // overrides a regex-only false positive.
    $cityRoute = makeAddress('PO Box 42', uspsCarrierRoute: 'C018');
    expect($cityRoute->isPoBox())->toBeFalse();
});

it('does not let a PO Box also read as a military address', function (): void {
    $address = makeAddress('PO Box 42');

    expect($address->isPoBox())->toBeTrue()
        ->and($address->isMilitary())->toBeFalse();
});

function addressIn(string $stateOrProvince, string $city = 'Anytown', string $country = 'US'): AddressData
{
    return new AddressData(
        firstName: 'John',
        lastName: 'Doe',
        streetAddress: 'PO BOX 1686',
        city: $city,
        stateOrProvince: $stateOrProvince,
        postalCode: '00754',
        country: $country,
    );
}

it('requires a customs declaration for a US territory', function (string $territory): void {
    expect(addressIn($territory)->isUsTerritory())->toBeTrue()
        ->and(addressIn($territory)->requiresCustomsDeclaration())->toBeTrue();
})->with(['PR', 'GU', 'VI', 'AS', 'MP']);

it('requires a customs declaration for a military post office', function (): void {
    expect(addressIn('AE', city: 'APO')->requiresCustomsDeclaration())->toBeTrue();
});

it('requires a customs declaration for a foreign address', function (): void {
    expect(addressIn('ON', country: 'CA')->requiresCustomsDeclaration())->toBeTrue();
});

it('does not require a customs declaration for a state', function (string $state): void {
    expect(addressIn($state)->isUsTerritory())->toBeFalse()
        ->and(addressIn($state)->requiresCustomsDeclaration())->toBeFalse();
})->with(['TX', 'AK', 'HI', 'DC']);

it('reads a territory code that arrives unnormalized', function (): void {
    expect(addressIn(' pr ')->isUsTerritory())->toBeTrue();
});

it('does not treat a foreign subdivision sharing a territory code as a territory', function (): void {
    // Paraná, Brazil.
    expect(addressIn('PR', country: 'BR')->isUsTerritory())->toBeFalse();
});

it('crosses a customs boundary between the states and a territory', function (): void {
    expect(addressIn('PR')->sharesCustomsZoneWith(addressIn('WA')))->toBeFalse();
});

it('crosses a customs boundary between two different territories', function (): void {
    expect(addressIn('PR')->sharesCustomsZoneWith(addressIn('GU')))->toBeFalse();
});

it('stays inside one customs area within a single territory', function (): void {
    expect(addressIn('PR', city: 'San Lorenzo')->sharesCustomsZoneWith(addressIn('PR', city: 'Ponce')))->toBeTrue();
});

it('stays inside one customs area between two states', function (): void {
    expect(addressIn('WA')->sharesCustomsZoneWith(addressIn('PA')))->toBeTrue();
});

it('crosses a customs boundary when only the origin is foreign', function (): void {
    expect(addressIn('PA')->sharesCustomsZoneWith(addressIn('ON', country: 'CA')))->toBeFalse();
});
