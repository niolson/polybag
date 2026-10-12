<?php

namespace Database\Seeders;

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Models\BoxSize;
use App\Models\Carrier;
use Illuminate\Database\Seeder;

/**
 * Carrier-supplied packaging, coded under the carrier's name so the set cannot
 * collide with {@see CommonBoxSizeSeeder}'s codes or with another carrier's.
 *
 * Rows are stored length ≥ width ≥ height. Packaging a carrier makes in more
 * than one shape shares one carrier identity across a row per shape, and the
 * label tells the packer which one is in hand. A code that already exists is
 * left alone, so reseeding never changes a row an operator owns.
 */
class CarrierPackagingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $boxes = [
            // USPS Priority Mail flat-rate packaging: published outside dimensions, free
            // from USPS, priced by the service to 70 lb regardless of weight.
            ['code' => 'USPS-01', 'label' => 'USPS Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsFlatRateEnvelope, 'length' => '12.5', 'width' => '9.5', 'height' => '0.5'],
            ['code' => 'USPS-02', 'label' => 'USPS Legal Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsLegalFlatRateEnvelope, 'length' => '15', 'width' => '9.5', 'height' => '0.5'],
            ['code' => 'USPS-03', 'label' => 'USPS Padded Flat Rate Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::UspsPaddedFlatRateEnvelope, 'length' => '12.5', 'width' => '9.5', 'height' => '0.5'],
            ['code' => 'USPS-04', 'label' => 'USPS Small Flat Rate Box', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UspsSmallFlatRateBox, 'length' => '8.69', 'width' => '5.44', 'height' => '1.75'],
            ['code' => 'USPS-05', 'label' => 'USPS Medium Flat Rate Box (top-loading)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UspsMediumFlatRateBox, 'length' => '11.25', 'width' => '8.75', 'height' => '6'],
            ['code' => 'USPS-06', 'label' => 'USPS Medium Flat Rate Box (side-loading)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UspsMediumFlatRateBox, 'length' => '14.13', 'width' => '12', 'height' => '3.5'],
            ['code' => 'USPS-07', 'label' => 'USPS Large Flat Rate Box', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::UspsLargeFlatRateBox, 'length' => '12.25', 'width' => '12', 'height' => '6'],

            // FedEx packaging. FedEx publishes inside dimensions, and two shapes each of
            // the Small and Medium Box.
            ['code' => 'FEDEX-01', 'label' => 'FedEx Envelope', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexEnvelope, 'length' => '12.5', 'width' => '9.5', 'height' => '1'],
            ['code' => 'FEDEX-02', 'label' => 'FedEx Pak', 'type' => BoxSizeType::PADDED_MAILER, 'carrier_packaging' => CarrierPackaging::FedexPak, 'length' => '14.75', 'width' => '11.75', 'height' => '1'],
            ['code' => 'FEDEX-03', 'label' => 'FedEx Small Box (flat)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexSmallBox, 'length' => '12.25', 'width' => '10.9', 'height' => '1.5'],
            ['code' => 'FEDEX-04', 'label' => 'FedEx Small Box (deep)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexSmallBox, 'length' => '11.25', 'width' => '8.75', 'height' => '2.63'],
            ['code' => 'FEDEX-05', 'label' => 'FedEx Medium Box (flat)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexMediumBox, 'length' => '13.25', 'width' => '11.5', 'height' => '2.38'],
            ['code' => 'FEDEX-06', 'label' => 'FedEx Medium Box (deep)', 'type' => BoxSizeType::BOX, 'carrier_packaging' => CarrierPackaging::FedexMediumBox, 'length' => '11.25', 'width' => '8.75', 'height' => '4.38'],
        ];

        foreach ($boxes as $box) {
            $isUsps = $box['carrier_packaging']->carrier() === Carrier::USPS;

            BoxSize::firstOrCreate(
                ['code' => $box['code']],
                [
                    ...$box,
                    'max_weight' => $isUsps ? '70' : '35',
                    'empty_weight' => '0',
                    'materials_cost' => $isUsps ? '0' : null,
                ],
            );
        }
    }
}
