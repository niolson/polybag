<?php

namespace Database\Seeders;

use App\Models\Carrier;
use Illuminate\Database\Seeder;

class CarrierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $usps = Carrier::create([
            'name' => 'USPS',
        ]);
        $usps->carrierServices()->createMany([
            ['name' => 'Ground Advantage', 'service_code' => 'USPS_GROUND_ADVANTAGE'],
            ['name' => 'Priority Mail', 'service_code' => 'PRIORITY_MAIL'],
            ['name' => 'Priority Mail Express', 'service_code' => 'PRIORITY_MAIL_EXPRESS'],
            ['name' => 'Priority Mail International', 'service_code' => 'PRIORITY_MAIL_INTERNATIONAL'],
        ]);

        $fedex = Carrier::create([
            'name' => 'FedEx',
        ]);
        $fedex->carrierServices()->createMany([
            ['name' => 'FedEx Ground Home Delivery', 'service_code' => 'GROUND_HOME_DELIVERY'],
            ['name' => 'FedEx Ground', 'service_code' => 'FEDEX_GROUND'],
            ['name' => 'FedEx Ground Economy', 'service_code' => 'SMART_POST'],
            ['name' => 'FedEx International Priority', 'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY'],
            ['name' => 'FedEx International Economy', 'service_code' => 'FEDEX_INTERNATIONAL_ECONOMY'],
            ['name' => 'FedEx Priority Overnight', 'service_code' => 'PRIORITY_OVERNIGHT'],
            ['name' => 'FedEx Standard Overnight', 'service_code' => 'STANDARD_OVERNIGHT'],
            ['name' => 'FedEx 2Day', 'service_code' => 'FEDEX_2_DAY'],
            ['name' => 'FedEx 2Day AM', 'service_code' => 'FEDEX_2_DAY_AM'],
            ['name' => 'FedEx Express Saver', 'service_code' => 'FEDEX_EXPRESS_SAVER'],
        ]);
    }
}
