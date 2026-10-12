<?php

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Models\BoxSize;
use App\Models\Setting;
use Database\Seeders\CarrierPackagingSeeder;
use Database\Seeders\CommonBoxSizeSeeder;
use Database\Seeders\OnceOnlySeeder;
use Database\Seeders\ReferenceDataSeeder;

it('seeds the common set active and fits it on one printed sheet', function (): void {
    $this->seed(CommonBoxSizeSeeder::class);

    // The barcode sheet holds 24 to a page.
    expect(BoxSize::count())->toBeLessThanOrEqual(24)
        ->and(BoxSize::where('active', false)->exists())->toBeFalse()
        ->and(BoxSize::whereNotNull('carrier_packaging')->exists())->toBeFalse();
});

it('seeds every fixed-size carrier packaging, inactive', function (): void {
    $this->seed(CarrierPackagingSeeder::class);

    foreach (CarrierPackaging::cases() as $packaging) {
        $seeded = BoxSize::where('carrier_packaging', $packaging)->exists();

        expect($seeded)->toBe(! in_array($packaging, CarrierPackagingSeeder::UNSIZED, true), $packaging->value);
    }

    expect(BoxSize::where('active', true)->exists())->toBeFalse();
});

it('seeds the carrier packaging as part of the reference-data sync, once only', function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $tube = BoxSize::where('carrier_packaging', CarrierPackaging::FedexTube)->firstOrFail();
    $count = BoxSize::count();

    expect(Setting::query()->whereKey(OnceOnlySeeder::markerFor('carrier-packaging-v1'))->exists())->toBeTrue();

    $tube->delete();
    $this->seed(ReferenceDataSeeder::class);

    // A deleted row stays deleted.
    expect(BoxSize::count())->toBe($count - 1)
        ->and(BoxSize::where('carrier_packaging', CarrierPackaging::FedexTube)->exists())->toBeFalse();
});

it('seeds every USPS Priority Mail flat-rate packaging as a box size the flat-rate services rate on', function (): void {
    $this->seed(CarrierPackagingSeeder::class);

    $expected = [
        [CarrierPackaging::UspsFlatRateEnvelope, BoxSizeType::PADDED_MAILER],
        [CarrierPackaging::UspsLegalFlatRateEnvelope, BoxSizeType::PADDED_MAILER],
        [CarrierPackaging::UspsPaddedFlatRateEnvelope, BoxSizeType::PADDED_MAILER],
        [CarrierPackaging::UspsSmallFlatRateBox, BoxSizeType::BOX],
        [CarrierPackaging::UspsMediumFlatRateBox, BoxSizeType::BOX],
        [CarrierPackaging::UspsLargeFlatRateBox, BoxSizeType::BOX],
    ];

    foreach ($expected as [$packaging, $type]) {
        $rows = BoxSize::where('carrier_packaging', $packaging)->get();

        expect($rows)->not->toBeEmpty($packaging->value);

        foreach ($rows as $row) {
            expect($row->type)->toBe($type, $row->label)
                ->and((float) $row->max_weight)->toBe(70.0, $row->label)
                ->and((float) $row->materials_cost)->toBe(0.0, $row->label);
        }
    }

    // The two medium box shapes share one carrier identity and are told apart by label.
    expect(BoxSize::where('carrier_packaging', CarrierPackaging::UspsMediumFlatRateBox)->count())->toBe(2);
});

it('seeds the USPS packaging at the outside dimensions USPS publishes', function (): void {
    $this->seed(CarrierPackagingSeeder::class);

    // Postal Store outside dimensions as length, width, height, rounded to the column's two decimals.
    $published = [
        'USPS-01' => [12.5, 9.5, 0.5],
        'USPS-02' => [15.0, 9.5, 0.5],
        'USPS-03' => [12.5, 9.5, 0.5],
        'USPS-04' => [8.69, 5.44, 1.75],
        'USPS-05' => [11.25, 8.75, 6.0],
        'USPS-06' => [14.13, 12.0, 3.5],
        'USPS-07' => [12.25, 12.0, 6.0],
    ];

    foreach ($published as $code => [$length, $width, $height]) {
        $row = BoxSize::where('code', $code)->firstOrFail();

        expect((float) $row->length)->toBe($length, "{$row->label} length")
            ->and((float) $row->width)->toBe($width, "{$row->label} width")
            ->and((float) $row->height)->toBe($height, "{$row->label} height");
    }
});

it('seeds both shapes of the FedEx Small and Medium Box at the dimensions FedEx publishes for One Rate', function (): void {
    $this->seed(CarrierPackagingSeeder::class);

    $published = [
        [CarrierPackaging::FedexSmallBox, [12.38, 10.88, 1.5]],
        [CarrierPackaging::FedexSmallBox, [11.25, 8.75, 2.63]],
        [CarrierPackaging::FedexMediumBox, [13.25, 11.5, 2.38]],
        [CarrierPackaging::FedexMediumBox, [11.25, 8.75, 4.38]],
    ];

    foreach ($published as [$packaging, [$length, $width, $height]]) {
        expect(BoxSize::where('carrier_packaging', $packaging)
            ->where('length', $length)
            ->where('width', $width)
            ->where('height', $height)
            ->exists())->toBeTrue("{$packaging->value} {$length}x{$width}x{$height}");
    }

    expect(BoxSize::where('carrier_packaging', CarrierPackaging::FedexSmallBox)->count())->toBe(2)
        ->and(BoxSize::where('carrier_packaging', CarrierPackaging::FedexMediumBox)->count())->toBe(2);
});

it('stores every seeded size longest side first and names each in its stored order', function (): void {
    $this->seed(CommonBoxSizeSeeder::class);
    $this->seed(CarrierPackagingSeeder::class);

    foreach (BoxSize::all() as $row) {
        expect((float) $row->length)->toBeGreaterThanOrEqual((float) $row->width, $row->label)
            ->and((float) $row->width)->toBeGreaterThanOrEqual((float) $row->height, $row->label);

        // A name that spells out dimensions spells them in stored order.
        if (preg_match('/(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)(?:x(\d+(?:\.\d+)?))?/', $row->label, $matches) === 1) {
            expect((float) $matches[1])->toBe((float) $row->length, $row->label)
                ->and((float) $matches[2])->toBe((float) $row->width, $row->label);

            if (isset($matches[3])) {
                expect((float) $matches[3])->toBe((float) $row->height, $row->label);
            }
        }
    }
});

it('gives every seeded box size its own code', function (): void {
    $this->seed(CommonBoxSizeSeeder::class);
    $this->seed(CarrierPackagingSeeder::class);

    expect(BoxSize::pluck('code')->unique()->count())->toBe(BoxSize::count())
        ->and(BoxSize::count())->toBe(13 + count(CarrierPackagingSeeder::PACKAGING));
});

it('reseeds the common set without duplicating box sizes', function (): void {
    $this->seed(CommonBoxSizeSeeder::class);
    $count = BoxSize::count();

    $this->seed(CommonBoxSizeSeeder::class);

    expect(BoxSize::count())->toBe($count);
});

it('leaves a box the operator already gave a seeded code unchanged', function (string $seeder, string $code): void {
    $operatorBox = BoxSize::factory()->create([
        'code' => $code,
        'label' => 'Our shoebox',
        'type' => BoxSizeType::BOX,
        'length' => 14,
        'width' => 8,
        'height' => 5,
        'carrier_packaging' => null,
    ]);

    $this->seed($seeder);

    $operatorBox->refresh();

    expect($operatorBox->label)->toBe('Our shoebox')
        ->and((float) $operatorBox->length)->toBe(14.0)
        ->and($operatorBox->active)->toBeTrue()
        ->and(BoxSize::where('code', $code)->count())->toBe(1);
})->with([
    'common set' => [CommonBoxSizeSeeder::class, '01'],
    'carrier packaging' => [CarrierPackagingSeeder::class, 'USPS-01'],
]);
