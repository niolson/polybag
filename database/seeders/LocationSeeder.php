<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * A real, deliverable origin every carrier accepts: UPS rejects a city
     * that does not match the ZIP, and FedEx will not buy without a shipper
     * phone. The street is Woodinville City Hall, a public building, and the
     * phone is from the fictional 555-0100 to 555-0199 range.
     */
    public function run(): void
    {
        Location::firstOrCreate(
            ['is_default' => true],
            [
                'name' => 'Main Warehouse',
                'first_name' => 'Shipping',
                'last_name' => 'Center',
                'address1' => '17301 133rd Ave NE',
                'city' => 'Woodinville',
                'state_or_province' => 'WA',
                'postal_code' => '98072',
                'country' => 'US',
                // Written here as well as by the model's `saving` hook because
                // `DatabaseSeeder` runs without model events.
                'phone' => '(425) 555-0100',
                'phone_e164' => '+14255550100',
                'timezone' => 'America/Los_Angeles',
                'is_default' => true,
                'active' => true,
            ]
        );
    }
}
