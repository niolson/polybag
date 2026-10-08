<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Services\Carriers\UspsAdapter;

/**
 * `international-customs-terms/05`: USPS never declares an origin the product
 * does not have, and `AESITN` is the Shipment's ITN, else the Canada
 * exemption, else the $2,500 exemption.
 */
function uspsCustomsFormFor(string $country, ?string $origin, ?string $exportItn = null): array
{
    $address = fn (string $country): AddressData => new AddressData(
        firstName: 'Test',
        lastName: 'Person',
        streetAddress: '1 Example Street',
        city: 'Example City',
        stateOrProvince: null,
        postalCode: '10115',
        country: $country,
    );

    $request = new ShipRequest(
        fromAddress: $address('US'),
        toAddress: $address($country),
        packageData: new PackageData(weight: 1.0, length: 6, width: 6, height: 6),
        customsItems: [new CustomsItem('Widget', 2, 20.0, 0.5, '950300', $origin)],
        exportItn: $exportItn,
    );

    $method = new ReflectionMethod(UspsAdapter::class, 'buildCustomsForm');

    return $method->invoke(app(UspsAdapter::class), $request);
}

it('declares the origin the product has, and none it does not', function (): void {
    $declared = uspsCustomsFormFor('JP', 'CN');
    $unknown = uspsCustomsFormFor('JP', null);

    expect($declared['contents'][0]['countryofOrigin'])->toBe('CN')
        ->and($unknown['contents'][0])->not->toHaveKey('countryofOrigin');
});

it('sends the ITN when EEI was filed', function (): void {
    expect(uspsCustomsFormFor('JP', 'US', 'X00000000000001')['AESITN'])->toBe('X00000000000001');
});

it('claims the Canada exemption to Canada and 30.37(a) elsewhere', function (): void {
    expect(uspsCustomsFormFor('CA', 'US')['AESITN'])->toBe('NO EEI 30.36')
        ->and(uspsCustomsFormFor('JP', 'US')['AESITN'])->toBe('NO EEI 30.37(a)');
});
