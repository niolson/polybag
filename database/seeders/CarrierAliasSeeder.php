<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\CarrierAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CarrierAliasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $aliases = [
            Carrier::USPS => ['US Postal Service', 'United States Postal Service'],
            Carrier::FEDEX => ['Federal Express'],
            Carrier::UPS => ['United Parcel Service'],
        ];

        foreach ($aliases as $carrierName => $carrierAliases) {
            $carrier = Carrier::query()->where('name', $carrierName)->first();

            if (! $carrier) {
                continue;
            }

            foreach ($carrierAliases as $alias) {
                DB::table('carrier_aliases')->insertOrIgnore([
                    'carrier_id' => $carrier->id,
                    'alias' => $alias,
                    'lookup_key' => CarrierAlias::lookupKey($alias),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
