<?php

use App\DataTransferObjects\Shipping\PackageData;
use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Exceptions\InvalidPackageDimensionsException;
use App\Models\BoxSize;
use App\Models\Package;

it('carries the box size\'s physical form and carrier packaging — ADR-0005\'s two axes', function (): void {
    $package = Package::factory()->create([
        'box_size_id' => BoxSize::factory()
            ->carrierPackaging(CarrierPackaging::UspsPaddedFlatRateEnvelope)
            ->create(['type' => BoxSizeType::PADDED_MAILER])
            ->id,
        'weight' => 1.25,
        'length' => 12.5,
        'width' => 9.5,
        'height' => 0.5,
    ]);

    $data = PackageData::fromPackage($package);

    expect($data->boxType)->toBe(BoxSizeType::PADDED_MAILER)
        ->and($data->carrierPackaging)->toBe(CarrierPackaging::UspsPaddedFlatRateEnvelope)
        ->and($data->weight)->toBe(1.25)
        ->and($data->length)->toBe(12.5);
});

it('reads null carrier packaging for the packer\'s own box and for no box size at all', function (): void {
    $ownBox = Package::factory()->create(['box_size_id' => BoxSize::factory()->create()->id]);
    $noBox = Package::factory()->create(['box_size_id' => null]);

    expect(PackageData::fromPackage($ownBox)->carrierPackaging)->toBeNull()
        ->and(PackageData::fromPackage($noBox)->carrierPackaging)->toBeNull()
        ->and(PackageData::fromPackage($noBox)->boxType)->toBeNull();
});

it('normalizes fractional dimensions upward to whole inches for carrier APIs', function (): void {
    $data = new PackageData(weight: 1.0, length: 12.01, width: 8.5, height: 0.1);

    expect($data->dimensionsInWholeInches())->toBe([
        'length' => 13,
        'width' => 9,
        'height' => 1,
    ]);
});

it('refuses unavailable or invalid dimensions instead of inventing measurements', function (float $length): void {
    $data = new PackageData(weight: 1.0, length: $length, width: 8, height: 6);

    expect(fn (): array => $data->dimensionsInWholeInches())
        ->toThrow(InvalidPackageDimensionsException::class, 'Package dimensions must be finite positive measurements.');
})->with([
    'zero' => 0.0,
    'negative' => -1.0,
    'not a number' => NAN,
    'infinite' => INF,
]);
