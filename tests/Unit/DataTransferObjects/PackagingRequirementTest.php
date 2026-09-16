<?php

use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\Enums\CarrierPackaging;

// ADR-0005 decision 3: the Package's identity is exact, the rate's requirement
// may match several, and `accepts()` is the only question anything asks.

it('accepts only the packer\'s own packaging when the rate requires shipper packaging', function (): void {
    $requirement = PackagingRequirement::shipperPackaging();

    expect($requirement->accepts(null))->toBeTrue()
        ->and($requirement->isShipperPackaging())->toBeTrue();

    foreach (CarrierPackaging::cases() as $packaging) {
        expect($requirement->accepts($packaging))->toBeFalse($packaging->name.' must not satisfy shipperPackaging()');
    }
});

it('accepts exactly one carrier packaging and nothing else', function (): void {
    $requirement = PackagingRequirement::exactly(CarrierPackaging::UspsFlatRateEnvelope);

    expect($requirement->accepts(CarrierPackaging::UspsFlatRateEnvelope))->toBeTrue()
        ->and($requirement->accepts(null))->toBeFalse()
        ->and($requirement->isShipperPackaging())->toBeFalse();

    // The Express envelope is separate stock with a separate service printed on
    // it — the case the enum's three Express cases exist to keep apart.
    expect($requirement->accepts(CarrierPackaging::UspsExpressFlatRateEnvelope))->toBeFalse()
        ->and($requirement->accepts(CarrierPackaging::UspsLegalFlatRateEnvelope))->toBeFalse()
        ->and($requirement->accepts(CarrierPackaging::FedexEnvelope))->toBeFalse();
});

it('accepts any listed carrier packaging and never null', function (): void {
    $requirement = PackagingRequirement::anyOf(
        CarrierPackaging::FedexEnvelope,
        CarrierPackaging::FedexPak,
        CarrierPackaging::FedexSmallBox,
    );

    expect($requirement->accepts(CarrierPackaging::FedexEnvelope))->toBeTrue()
        ->and($requirement->accepts(CarrierPackaging::FedexPak))->toBeTrue()
        ->and($requirement->accepts(CarrierPackaging::FedexSmallBox))->toBeTrue()
        ->and($requirement->accepts(CarrierPackaging::FedexMediumBox))->toBeFalse()
        ->and($requirement->accepts(CarrierPackaging::UpsPak))->toBeFalse()
        ->and($requirement->accepts(null))->toBeFalse();
});

it('treats anyOf() with no packaging as a programming error', function (): void {
    PackagingRequirement::anyOf();
})->throws(InvalidArgumentException::class);

it('round-trips each constructor kind through toArray() and fromArray()', function (PackagingRequirement $requirement): void {
    $restored = PackagingRequirement::fromArray($requirement->toArray());

    expect($restored->toArray())->toBe($requirement->toArray())
        ->and($restored->accepts(null))->toBe($requirement->accepts(null));

    foreach (CarrierPackaging::cases() as $packaging) {
        expect($restored->accepts($packaging))->toBe($requirement->accepts($packaging));
    }
})->with([
    'shipperPackaging' => [fn (): PackagingRequirement => PackagingRequirement::shipperPackaging()],
    'exactly' => [fn (): PackagingRequirement => PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox)],
    'anyOf' => [fn (): PackagingRequirement => PackagingRequirement::anyOf(CarrierPackaging::UpsLetter, CarrierPackaging::UpsPak)],
]);

it('serializes to the enum values, which is what an offer row and Livewire state hold', function (): void {
    expect(PackagingRequirement::exactly(CarrierPackaging::UspsSmallFlatRateBox)->toArray())->toBe([
        'kind' => 'exactly',
        'packagings' => ['usps_small_flat_rate_box'],
    ])->and(PackagingRequirement::shipperPackaging()->toArray())->toBe([
        'kind' => 'shipper_packaging',
        'packagings' => [],
    ]);
});

it('reads a missing rate-metadata key as shipper packaging, the direction that accepts nothing a carrier supplies', function (): void {
    $fromLegacyOffer = PackagingRequirement::fromRateMetadata(['mailClass' => 'USPS_GROUND_ADVANTAGE']);

    expect($fromLegacyOffer->isShipperPackaging())->toBeTrue()
        ->and($fromLegacyOffer->accepts(null))->toBeTrue()
        ->and($fromLegacyOffer->accepts(CarrierPackaging::UspsFlatRateEnvelope))->toBeFalse();
});

it('reads the requirement stored under its rate-metadata key', function (): void {
    $stored = [
        'serviceType' => 'FEDEX_2_DAY',
        PackagingRequirement::RATE_METADATA_KEY => PackagingRequirement::anyOf(
            CarrierPackaging::FedexEnvelope,
            CarrierPackaging::FedexPak,
        )->toArray(),
    ];

    $restored = PackagingRequirement::fromRateMetadata($stored);

    expect($restored->accepts(CarrierPackaging::FedexPak))->toBeTrue()
        ->and($restored->accepts(null))->toBeFalse();
});

it('names the packaging for a message to the packer', function (): void {
    expect(PackagingRequirement::shipperPackaging()->describe())->toBe('your own packaging')
        ->and(PackagingRequirement::exactly(CarrierPackaging::UspsSmallFlatRateBox)->describe())->toBe('USPS Small Flat Rate Box')
        ->and(PackagingRequirement::anyOf(CarrierPackaging::FedexEnvelope, CarrierPackaging::FedexPak)->describe())
        ->toBe('one of FedEx Envelope, FedEx Pak');
});
