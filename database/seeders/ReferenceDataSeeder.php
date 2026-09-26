<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    /**
     * Seed tenant-wide reference data that should exist in every environment.
     */
    public function run(): void
    {
        $this->call([
            CarrierSeeder::class,
            // After the catalog it maps, and once only: removing one sticks.
            ShopifyServiceMappingSeeder::class,
            CarrierAliasSeeder::class,
            SpecialServiceSeeder::class,
            CarrierServiceSpecialServiceSeeder::class,
        ]);
    }
}
