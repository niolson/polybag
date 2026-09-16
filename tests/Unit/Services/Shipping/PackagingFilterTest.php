<?php

use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\CarrierPackaging;
use App\Services\Shipping\PackagingFilter;

function rateRequiring(string $serviceCode, PackagingRequirement $requirement): RateResponse
{
    return new RateResponse(
        carrier: 'USPS',
        serviceCode: $serviceCode,
        serviceName: $serviceCode,
        price: 8.00,
        packagingRequirement: $requirement,
    );
}

it('keeps only rates whose requirement accepts the packaging the package is in', function (): void {
    $rates = collect([
        rateRequiring('OWN_BOX', PackagingRequirement::shipperPackaging()),
        rateRequiring('SMALL_FLAT_RATE_BOX', PackagingRequirement::exactly(CarrierPackaging::UspsSmallFlatRateBox)),
        rateRequiring('ANY_FLAT_RATE_BOX', PackagingRequirement::anyOf(
            CarrierPackaging::UspsSmallFlatRateBox,
            CarrierPackaging::UspsMediumFlatRateBox,
        )),
    ]);

    expect(PackagingFilter::keepCompatible($rates, null)->pluck('serviceCode')->all())
        ->toBe(['OWN_BOX'])
        ->and(PackagingFilter::keepCompatible($rates, CarrierPackaging::UspsSmallFlatRateBox)->pluck('serviceCode')->all())
        ->toBe(['SMALL_FLAT_RATE_BOX', 'ANY_FLAT_RATE_BOX'])
        ->and(PackagingFilter::keepCompatible($rates, CarrierPackaging::UspsMediumFlatRateBox)->pluck('serviceCode')->all())
        ->toBe(['ANY_FLAT_RATE_BOX'])
        ->and(PackagingFilter::keepCompatible($rates, CarrierPackaging::FedexPak)->all())
        ->toBe([]);
});

it('reindexes what it keeps, so the Ship page\'s positional selection still lines up', function (): void {
    $kept = PackagingFilter::keepCompatible(collect([
        rateRequiring('FLAT_RATE', PackagingRequirement::exactly(CarrierPackaging::UspsFlatRateEnvelope)),
        rateRequiring('OWN_BOX', PackagingRequirement::shipperPackaging()),
    ]), null);

    expect($kept->keys()->all())->toBe([0])
        ->and($kept[0]->serviceCode)->toBe('OWN_BOX');
});
