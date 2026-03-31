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
        ]);
    }
}
