<?php

namespace Database\Seeders;

use App\Enums\PostageSourceKind;
use App\Models\CarrierService;
use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

class ShippingMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Methods only, no aliases: what an order source calls each method is its
     * own code, which no starter set can guess. A guessed alias would quietly
     * map a real ERP's "1" or "standard" to whichever method we picked.
     */
    public function run(): void
    {
        // Standard Ground - USPS Ground Advantage, UPS Ground, FedEx Ground and
        // Home Delivery (commercial and residential, so rating picks by destination).
        // A late ground parcel is still worth buying automatically; 2 Day and
        // Overnight leave a late one for a person, the column's default.
        $standardGround = ShippingMethod::firstOrCreate(
            ['name' => 'Standard Ground'],
            ['commitment_days' => 5, 'excludes_late_rates' => false, 'active' => true],
        );
        $standardGround->carrierServices()->syncWithoutDetaching(
            CarrierService::whereIn('service_code', [
                'USPS_GROUND_ADVANTAGE',
                '03',
                'FEDEX_GROUND',
                'GROUND_HOME_DELIVERY',
            ])->pluck('id')
        );

        // 2 Day - Priority Mail, FedEx 2Day
        $twoDay = ShippingMethod::firstOrCreate(
            ['name' => '2 Day'],
            ['commitment_days' => 2, 'active' => true],
        );
        $twoDay->carrierServices()->syncWithoutDetaching(
            CarrierService::whereIn('service_code', [
                'PRIORITY_MAIL',
                'FEDEX_2_DAY',
                'FEDEX_2_DAY_AM',
            ])->pluck('id')
        );

        // International Economy - USPS Priority Mail International, FedEx International Economy
        $internationalEconomy = ShippingMethod::firstOrCreate(
            ['name' => 'International Economy'],
            ['commitment_days' => 5, 'active' => true],
        );
        $internationalEconomy->carrierServices()->syncWithoutDetaching(
            CarrierService::whereIn('service_code', [
                'PRIORITY_MAIL_INTERNATIONAL',
                'INTERNATIONAL_ECONOMY',
            ])->pluck('id')
        );

        // Overnight - Priority Mail Express, FedEx Priority/Standard Overnight
        $overnight = ShippingMethod::firstOrCreate(
            ['name' => 'Overnight'],
            ['commitment_days' => 1, 'active' => true],
        );
        $overnight->carrierServices()->syncWithoutDetaching(
            CarrierService::whereIn('service_code', [
                'PRIORITY_MAIL_EXPRESS',
                'PRIORITY_OVERNIGHT',
                'STANDARD_OVERNIGHT',
            ])->pluck('id')
        );

        foreach ([$standardGround, $twoDay, $internationalEconomy, $overnight] as $method) {
            $this->allowDirectIfNew($method);
        }
    }

    /**
     * Direct on, as for any new method. Written here as well as by the model's
     * `created` hook because `DatabaseSeeder` runs without model events. Only
     * for a method this run created: a row an Admin deleted from an existing
     * method stays deleted.
     */
    private function allowDirectIfNew(ShippingMethod $method): void
    {
        if ($method->wasRecentlyCreated) {
            $method->postageSources()->firstOrCreate(['source_kind' => PostageSourceKind::Direct]);
        }
    }
}
