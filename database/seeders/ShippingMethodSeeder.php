<?php

namespace Database\Seeders;

use App\Models\CarrierService;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use Illuminate\Database\Seeder;

class ShippingMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Standard Ground - USPS Ground Advantage, FedEx Home Delivery
        $standardGround = ShippingMethod::create([
            'name' => 'Standard Ground',
            'commitment_days' => 5,
            'saturday_delivery' => false,
            'active' => true,
        ]);
        ShippingMethodAlias::create(['reference' => '1', 'shipping_method_id' => $standardGround->id]);
        $standardGround->carrierServices()->attach(
            CarrierService::whereIn('service_code', [
                'USPS_GROUND_ADVANTAGE',
                'GROUND_HOME_DELIVERY',
            ])->pluck('id')
        );

        // 2 Day - Priority Mail, FedEx 2Day
        $twoDay = ShippingMethod::create([
            'name' => '2 Day',
            'commitment_days' => 2,
            'saturday_delivery' => false,
            'active' => true,
        ]);
        ShippingMethodAlias::create(['reference' => '2', 'shipping_method_id' => $twoDay->id]);
        $twoDay->carrierServices()->attach(
            CarrierService::whereIn('service_code', [
                'PRIORITY_MAIL',
                'FEDEX_2_DAY',
                'FEDEX_2_DAY_AM',
            ])->pluck('id')
        );

        // International Economy - USPS Priority Mail International, FedEx International Economy
        $internationalEconomy = ShippingMethod::create([
            'name' => 'International Economy',
            'commitment_days' => 5,
            'saturday_delivery' => false,
            'active' => true,
        ]);
        ShippingMethodAlias::create(['reference' => '3', 'shipping_method_id' => $internationalEconomy->id]);
        $internationalEconomy->carrierServices()->attach(
            CarrierService::whereIn('service_code', [
                'PRIORITY_MAIL_INTERNATIONAL',
                'FEDEX_INTERNATIONAL_ECONOMY',
            ])->pluck('id')
        );

        // Overnight - Priority Mail Express, FedEx Priority/Standard Overnight
        $overnight = ShippingMethod::create([
            'name' => 'Overnight',
            'commitment_days' => 1,
            'saturday_delivery' => false,
            'active' => true,
        ]);
        ShippingMethodAlias::create(['reference' => '4', 'shipping_method_id' => $overnight->id]);
        $overnight->carrierServices()->attach(
            CarrierService::whereIn('service_code', [
                'PRIORITY_MAIL_EXPRESS',
                'PRIORITY_OVERNIGHT',
                'STANDARD_OVERNIGHT',
            ])->pluck('id')
        );
    }
}
