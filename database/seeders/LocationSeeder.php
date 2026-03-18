<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        Location::firstOrCreate(
            ['is_default' => true],
            [
                'name' => 'Main Warehouse',
                'first_name' => 'Shipping',
                'last_name' => 'Center',
                'address1' => '123 Main St',
                'city' => 'Anytown',
                'state_or_province' => 'WA',
                'postal_code' => '98072',
                'country' => 'US',
                'timezone' => 'America/New_York',
                'is_default' => true,
                'active' => true,
            ]
        );
    }
}
