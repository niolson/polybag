<?php

namespace Database\Seeders;

use App\Models\BoxSize;
use Illuminate\Database\Seeder;

class BoxSizeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $boxes = [
            ['code' => '01', 'height' => '4', 'width' => '4', 'length' => '4', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '4x4x4', 'type' => 'BOX'],
            ['code' => '02', 'height' => '6', 'width' => '6', 'length' => '4', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '6x6x4', 'type' => 'BOX'],
            ['code' => '03', 'height' => '6', 'width' => '6', 'length' => '6', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '6x6x6', 'type' => 'BOX'],
            ['code' => '04', 'height' => '8', 'width' => '7', 'length' => '6', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '8x7x6', 'type' => 'BOX'],
            ['code' => '06', 'height' => '8', 'width' => '8', 'length' => '8', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '8x8x8', 'type' => 'BOX'],
            ['code' => '05', 'height' => '11', 'width' => '7', 'length' => '6', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '11x7x6', 'type' => 'BOX'],
            ['code' => '07', 'height' => '10', 'width' => '10', 'length' => '10', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '10x10x10', 'type' => 'BOX'],
            ['code' => '09', 'height' => '14', 'width' => '12', 'length' => '12', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '14x12x12', 'type' => 'BOX'],
            ['code' => '08', 'height' => '16', 'width' => '10', 'length' => '8', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '16x10x8', 'type' => 'BOX'],
            ['code' => '10', 'height' => '16', 'width' => '12', 'length' => '12', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '16x12x12', 'type' => 'BOX'],
            ['code' => '11', 'height' => '6', 'width' => '6', 'length' => '10', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '6x6x10', 'type' => 'BOX'],
            ['code' => '12', 'height' => '6', 'width' => '4', 'length' => '4', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '6x4x4', 'type' => 'BOX'],
            ['code' => '13', 'height' => '8', 'width' => '8', 'length' => '3', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '8x8x3', 'type' => 'BOX'],
            ['code' => '14', 'height' => '7.5', 'width' => '10.5', 'length' => '0.525', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '7.5x10.5x0.525', 'type' => 'PADDED_MAILER'],
            ['code' => '15', 'height' => '9', 'width' => '12', 'length' => '0.5', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '9x12x0.5', 'type' => 'PADDED_MAILER'],
            ['code' => '16', 'height' => '9.5', 'width' => '12.5', 'length' => '0.5', 'max_weight' => '70', 'empty_weight' => '0', 'materials_cost' => '0', 'label' => 'USPS Padded Flat Rate Envelope', 'type' => 'PADDED_MAILER', 'carrier_packaging' => 'usps_padded_flat_rate_envelope'],
            ['code' => '17', 'height' => '10', 'width' => '8', 'length' => '8', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '10x8x8', 'type' => 'BOX'],
            ['code' => '18', 'height' => '8.75', 'width' => '2.7', 'length' => '2', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'FedEx Small Box', 'type' => 'BOX', 'carrier_packaging' => 'fedex_small_box'],
            ['code' => '19', 'height' => '8.75', 'width' => '4.4', 'length' => '11.3', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'FedEx Medium Box', 'type' => 'BOX', 'carrier_packaging' => 'fedex_medium_box'],
            ['code' => '20', 'height' => '9.5', 'width' => '12.5', 'length' => '1', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'FedEx Envelope', 'type' => 'PADDED_MAILER', 'carrier_packaging' => 'fedex_envelope'],
            ['code' => '21', 'height' => '11.75', 'width' => '14.75', 'length' => '1', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'FedEx Pak', 'type' => 'PADDED_MAILER', 'carrier_packaging' => 'fedex_pak'],
            // USPS Priority Mail flat-rate packaging: published outside dimensions, free
            // from USPS, priced by the service to 70 lb regardless of weight. The medium
            // box comes in two shapes that share one carrier identity, so both are
            // seeded and the label tells the packer which one is in hand.
            ['code' => '22', 'height' => '9.5', 'width' => '12.5', 'length' => '0.5', 'max_weight' => '70', 'empty_weight' => '0', 'materials_cost' => '0', 'label' => 'USPS Flat Rate Envelope', 'type' => 'PADDED_MAILER', 'carrier_packaging' => 'usps_flat_rate_envelope'],
            ['code' => '23', 'height' => '9.5', 'width' => '15', 'length' => '0.5', 'max_weight' => '70', 'empty_weight' => '0', 'materials_cost' => '0', 'label' => 'USPS Legal Flat Rate Envelope', 'type' => 'PADDED_MAILER', 'carrier_packaging' => 'usps_legal_flat_rate_envelope'],
            ['code' => '24', 'height' => '8.69', 'width' => '5.44', 'length' => '1.75', 'max_weight' => '70', 'empty_weight' => '0', 'materials_cost' => '0', 'label' => 'USPS Small Flat Rate Box', 'type' => 'BOX', 'carrier_packaging' => 'usps_small_flat_rate_box'],
            ['code' => '25', 'height' => '11.25', 'width' => '8.75', 'length' => '6', 'max_weight' => '70', 'empty_weight' => '0', 'materials_cost' => '0', 'label' => 'USPS Medium Flat Rate Box (top-loading)', 'type' => 'BOX', 'carrier_packaging' => 'usps_medium_flat_rate_box'],
            ['code' => '26', 'height' => '14', 'width' => '12', 'length' => '3.5', 'max_weight' => '70', 'empty_weight' => '0', 'materials_cost' => '0', 'label' => 'USPS Medium Flat Rate Box (side-loading)', 'type' => 'BOX', 'carrier_packaging' => 'usps_medium_flat_rate_box'],
            ['code' => '27', 'height' => '12.25', 'width' => '12.25', 'length' => '6', 'max_weight' => '70', 'empty_weight' => '0', 'materials_cost' => '0', 'label' => 'USPS Large Flat Rate Box', 'type' => 'BOX', 'carrier_packaging' => 'usps_large_flat_rate_box'],
        ];

        foreach ($boxes as $box) {
            $code = $box['code'];
            unset($box['code']);

            BoxSize::updateOrCreate(
                ['code' => $code],
                $box,
            );
        }
    }
}
