<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BoxSizeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('box_sizes')->insert([
            ['code' => '01', 'height' => '4', 'width' => '4', 'length' => '4', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '4x4x4', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '02', 'height' => '6', 'width' => '6', 'length' => '4', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '6x6x4', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '03', 'height' => '6', 'width' => '6', 'length' => '6', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '6x6x6', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '04', 'height' => '8', 'width' => '7', 'length' => '6', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '8x7x6', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '06', 'height' => '8', 'width' => '8', 'length' => '8', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '8x8x8', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '05', 'height' => '11', 'width' => '7', 'length' => '6', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '11x7x6', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '07', 'height' => '10', 'width' => '10', 'length' => '10', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '10x10x10', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '09', 'height' => '14', 'width' => '12', 'length' => '12', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '14x12x12', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '08', 'height' => '16', 'width' => '10', 'length' => '8', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '16x10x8', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '10', 'height' => '16', 'width' => '12', 'length' => '12', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '16x12x12', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],

            ['code' => '11', 'height' => '6', 'width' => '6', 'length' => '10', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '6x6x10', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '12', 'height' => '6', 'width' => '4', 'length' => '4', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '6x4x4', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '13', 'height' => '8', 'width' => '8', 'length' => '3', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '8x8x3', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '14', 'height' => '7.5', 'width' => '10.5', 'length' => '0.525', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '7.5x10.5x0.525', 'type' => 'PADDED_MAILER', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '15', 'height' => '9', 'width' => '12', 'length' => '0.5', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '9x12x0.5', 'type' => 'PADDED_MAILER', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '16', 'height' => '9.5', 'width' => '12.5', 'length' => '0.5', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'USPS Flat Rate Padded Envelope', 'type' => 'PADDED_MAILER', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '17', 'height' => '10', 'width' => '8', 'length' => '8', 'max_weight' => '35', 'empty_weight' => '0', 'label' => '10x8x8', 'type' => 'BOX', 'fedex_package_type' => 'YOUR_PACKAGING'],
            ['code' => '18', 'height' => '8.75', 'width' => '2.7', 'length' => '2', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'FedEx Small Box', 'type' => 'BOX', 'fedex_package_type' => 'FEDEX_SMALL_BOX'],
            ['code' => '19', 'height' => '8.75', 'width' => '4.4', 'length' => '11.3', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'FedEx Medium Box', 'type' => 'BOX', 'fedex_package_type' => 'FEDEX_MEDIUM_BOX'],
            ['code' => '20', 'height' => '9.5', 'width' => '12.5', 'length' => '1', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'FedEx Envelope', 'type' => 'PADDED_MAILER', 'fedex_package_type' => 'FEDEX_ENVELOPE'],
            ['code' => '21', 'height' => '11.75', 'width' => '14.75', 'length' => '1', 'max_weight' => '35', 'empty_weight' => '0', 'label' => 'FedEx Pak', 'type' => 'PADDED_MAILER', 'fedex_package_type' => 'FEDEX_PAK'],
        ]);
    }
}
