<?php

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\CarrierPackaging;
use App\Enums\PostageSourceKind;

it('round-trips the offer identifier through Livewire serialization', function (): void {
    $rate = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'OnTrac Ground',
        price: 5.79,
        offerId: '01K4XJ5S8ZQ7V6R3N2M1P0T9AB',
    );

    expect(RateResponse::fromArray($rate->toArray())->offerId)->toBe('01K4XJ5S8ZQ7V6R3N2M1P0T9AB');
});

it('carries nothing that could buy a label on its own', function (): void {
    $rate = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'OnTrac Ground',
        price: 5.79,
        offerId: '01K4XJ5S8ZQ7V6R3N2M1P0T9AB',
    );

    // ADR-0002 decision 4: the browser holds the opaque identifier, and the
    // tokens, source instance, environment and expiry stay server-side. This
    // array is browser state.
    expect(array_keys($rate->toArray()))->toBe([
        'carrier',
        'serviceCode',
        'serviceName',
        'price',
        'deliveryCommitment',
        'deliveryDate',
        'transitTime',
        'metadata',
        'priceUnknown',
        'offerId',
        'observedService',
        'packagingRequirement',
        // Which account quoted it — a number the offer already records, and
        // nothing the purchase reads back off the browser.
        'carrierAccountId',
        // Which catalog service and carrier it is for. Descriptive, and
        // restored from the offer at purchase like the price
        // (`carrier-catalog-reset/02`).
        'carrierServiceId',
        'carrierId',
        // Whether only a person may choose it. Display only: automation never
        // reads browser state, and a purchase restores its rate from the
        // offer (`carrier-catalog-reset/04`).
        'contentRestricted',
    ]);
});

it('round-trips whether a rate is content-restricted, and keeps it behind an offer', function (): void {
    $rate = new RateResponse(
        carrier: 'USPS',
        serviceCode: 'USPS_PTP_BPM',
        serviceName: 'USPS Bound Printed Matter',
        price: 4.10,
        contentRestricted: true,
    );

    expect(RateResponse::fromArray($rate->toArray())->contentRestricted)->toBeTrue()
        ->and($rate->withOfferId('01K4XJ5S8ZQ7V6R3N2M1P0T9AB')->contentRestricted)->toBeTrue()
        // An array serialized before the flag existed reads as unrestricted.
        ->and(RateResponse::fromArray(array_diff_key($rate->toArray(), ['contentRestricted' => true]))->contentRestricted)->toBeFalse();
});

it('round-trips the catalog service and carrier it names', function (): void {
    $rate = (new RateResponse(
        carrier: 'USPS',
        serviceCode: 'MEDIA_MAIL',
        serviceName: 'Media Mail Machinable Single-piece',
        price: 5.13,
    ))->withCatalogIdentity(carrierId: 3, carrierServiceId: 17);

    $restored = RateResponse::fromArray($rate->toArray());

    expect($restored->carrierServiceId)->toBe(17)
        ->and($restored->carrierId)->toBe(3);
});

it('keeps its catalog identity when an offer is put behind it', function (): void {
    $rate = (new RateResponse(
        carrier: 'USPS',
        serviceCode: 'MEDIA_MAIL',
        serviceName: 'Media Mail Machinable Single-piece',
        price: 5.13,
        carrierAccountId: 9,
    ))->withCatalogIdentity(carrierId: 3, carrierServiceId: 17)->withOfferId('01K4XJ5S8ZQ7V6R3N2M1P0T9AB');

    expect($rate->carrierServiceId)->toBe(17)
        ->and($rate->carrierId)->toBe(3)
        ->and($rate->carrierAccountId)->toBe(9)
        ->and($rate->offerId)->toBe('01K4XJ5S8ZQ7V6R3N2M1P0T9AB');
});

it('names no catalog service when told it has none', function (): void {
    $rate = (new RateResponse(
        carrier: 'USPS',
        serviceCode: 'PARCEL_SELECT',
        serviceName: 'Parcel Select',
        price: 6.30,
        carrierServiceId: 17,
    ))->withCatalogIdentity(carrierId: 3, carrierServiceId: null);

    expect($rate->carrierServiceId)->toBeNull()
        ->and($rate->carrierId)->toBe(3);
});

it('round-trips the observed service identity, which names a service rather than authorizing one', function (): void {
    // The identity is how `RateSelector` tells Buy Shipping's rates from
    // direct ones, so a round trip that quietly dropped it would judge an
    // Amazon offer by the method's `direct` row. It is safe in browser state
    // for the same reason it is not purchase authority: it says which service
    // Amazon named, which the page already shows, and automation only ever sees
    // rates that came straight from the quote.
    $rate = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'OnTrac Ground',
        price: 5.79,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            externalCarrierId: 'ONTRAC',
            externalServiceId: 'ONTRAC_MFN_GROUND',
        ),
    );

    $restored = RateResponse::fromArray($rate->toArray());

    expect($restored->observedService)->toEqual($rate->observedService)
        ->and($restored->sourceKind())->toBe(PostageSourceKind::Amazon);
});

it('ignores the environment and channel type a rate serialized before carrier-catalog-reset/13 carries', function (): void {
    $data = (new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'OnTrac Ground',
        price: 5.79,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            externalCarrierId: 'ONTRAC',
            externalServiceId: 'ONTRAC_MFN_GROUND',
        ),
    ))->toArray();
    $data['observedService'] += ['environment' => 'production', 'channelType' => 'AMAZON'];

    expect(RateResponse::fromArray($data)->observedService?->externalServiceId)->toBe('ONTRAC_MFN_GROUND');
});

it('defaults to no observed service, so an authored carrier service is not treated as discovered', function (): void {
    $rate = new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 6.93);

    expect($rate->observedService)->toBeNull()
        ->and(RateResponse::fromArray($rate->toArray())->observedService)->toBeNull();
});

it('defaults to no offer, for rates from sources that issue none', function (): void {
    $rate = new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 6.93);

    expect($rate->offerId)->toBeNull()
        ->and(RateResponse::fromArray([
            'carrier' => 'USPS',
            'serviceCode' => 'USPS_GROUND_ADVANTAGE',
            'serviceName' => 'Ground Advantage',
            'price' => 6.93,
            'deliveryCommitment' => null,
            'deliveryDate' => null,
            'transitTime' => null,
        ])->offerId)->toBeNull();
});

it('round-trips the packaging requirement through Livewire serialization', function (PackagingRequirement $requirement): void {
    // ADR-0005 consequence: rates cross Livewire state on the Ship page through
    // this serialization, and a requirement that did not survive it would let a
    // rate chosen from the page be bought without the check that hid its siblings.
    $rate = new RateResponse(
        carrier: 'USPS',
        serviceCode: 'PRIORITY_MAIL',
        serviceName: 'Priority Mail',
        price: 9.65,
        packagingRequirement: $requirement,
    );

    $restored = RateResponse::fromArray($rate->toArray());

    expect($restored->packagingRequirement->toArray())->toBe($requirement->toArray());

    foreach ([null, ...CarrierPackaging::cases()] as $packaging) {
        expect($restored->packagingRequirement->accepts($packaging))->toBe($requirement->accepts($packaging));
    }
})->with([
    'shipperPackaging' => [fn (): PackagingRequirement => PackagingRequirement::shipperPackaging()],
    'exactly' => [fn (): PackagingRequirement => PackagingRequirement::exactly(CarrierPackaging::UspsPaddedFlatRateEnvelope)],
    'anyOf' => [fn (): PackagingRequirement => PackagingRequirement::anyOf(CarrierPackaging::FedexEnvelope, CarrierPackaging::FedexPak, CarrierPackaging::FedexTube)],
]);

it('reads a legacy array with no packaging key as shipper packaging', function (): void {
    // The safe direction: an array serialized before the requirement existed
    // accepts only the packer's own packaging, never anything a carrier supplies.
    $restored = RateResponse::fromArray([
        'carrier' => 'USPS',
        'serviceCode' => 'USPS_GROUND_ADVANTAGE',
        'serviceName' => 'Ground Advantage',
        'price' => 6.93,
        'deliveryCommitment' => null,
        'deliveryDate' => null,
        'transitTime' => null,
    ]);

    expect($restored->packagingRequirement->isShipperPackaging())->toBeTrue()
        ->and($restored->packagingRequirement->accepts(null))->toBeTrue()
        ->and($restored->packagingRequirement->accepts(CarrierPackaging::UspsSmallFlatRateBox))->toBeFalse();
});

it('defaults to shipper packaging, so a hand-built rate is never valid in carrier packaging', function (): void {
    $rate = new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 6.93);

    expect($rate->packagingRequirement->isShipperPackaging())->toBeTrue();
});
