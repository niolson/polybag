<?php

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\CarrierPackaging;
use App\Enums\SourceEnvironment;

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
    ]);
});

it('round-trips the observed service identity, which names a service rather than authorizing one', function (): void {
    // ADR-0003 decision 4. The identity is what `RateSelector` asks the approval
    // gate about, so a round trip that quietly dropped it would turn a
    // discovered service back into an authored one — fail-open, in the one place
    // that must not be. It is safe in browser state for the same reason it is
    // not purchase authority: it says which service Amazon named, which the page
    // already shows as a carrier and a service name, and nothing reads it back
    // off the browser to decide anything. Automation only ever sees rates that
    // came straight from the quote.
    $rate = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'OnTrac Ground',
        price: 5.79,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            environment: SourceEnvironment::Production,
            externalCarrierId: 'ONTRAC',
            externalServiceId: 'ONTRAC_MFN_GROUND',
        ),
    );

    $restored = RateResponse::fromArray($rate->toArray());

    expect($restored->observedService?->approvalKey())->toBe($rate->observedService->approvalKey())
        ->and($restored->observedService?->environment)->toBe(SourceEnvironment::Production);
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
