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
