<?php

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Models\BoxSize;
use Database\Seeders\BoxSizeSeeder;

it('seeds every USPS Priority Mail flat-rate packaging as a box size the flat-rate services rate on', function (): void {
    $this->seed(BoxSizeSeeder::class);

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

    // Express envelopes are separate USPS stock and are not seeded.
    expect(BoxSize::whereIn('carrier_packaging', [
        CarrierPackaging::UspsExpressFlatRateEnvelope,
        CarrierPackaging::UspsExpressLegalFlatRateEnvelope,
        CarrierPackaging::UspsExpressPaddedFlatRateEnvelope,
    ])->exists())->toBeFalse();
});

it('seeds the USPS packaging at the outside dimensions USPS publishes', function (): void {
    $this->seed(BoxSizeSeeder::class);

    // Postal Store outside dimensions, rounded to the column's two decimals.
    $published = [
        '16' => [9.5, 12.5, 0.5],
        '22' => [9.5, 12.5, 0.5],
        '23' => [9.5, 15.0, 0.5],
        '24' => [8.69, 5.44, 1.75],
        '25' => [11.25, 8.75, 6.0],
        '26' => [14.13, 12.0, 3.5],
        '27' => [12.25, 12.0, 6.0],
    ];

    foreach ($published as $code => [$height, $width, $length]) {
        $row = BoxSize::where('code', $code)->firstOrFail();

        expect((float) $row->height)->toBe($height, "{$row->label} height")
            ->and((float) $row->width)->toBe($width, "{$row->label} width")
            ->and((float) $row->length)->toBe($length, "{$row->label} length");
    }
});

it('reseeds without duplicating box sizes or disturbing an operator edit to one', function (): void {
    $this->seed(BoxSizeSeeder::class);
    $count = BoxSize::count();

    $this->seed(BoxSizeSeeder::class);

    expect(BoxSize::count())->toBe($count)
        ->and(BoxSize::pluck('code')->unique()->count())->toBe($count);
});

it('stamps the padded flat-rate envelope that earlier installs seeded without a carrier identity', function (): void {
    BoxSize::factory()->create([
        'code' => '16',
        'label' => 'USPS Flat Rate Padded Envelope',
        'type' => BoxSizeType::PADDED_MAILER,
        'carrier_packaging' => null,
    ]);

    $this->seed(BoxSizeSeeder::class);

    expect(BoxSize::where('code', '16')->value('carrier_packaging'))->toBe(CarrierPackaging::UspsPaddedFlatRateEnvelope);
});
