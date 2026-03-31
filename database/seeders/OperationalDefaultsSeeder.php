<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class OperationalDefaultsSeeder extends Seeder
{
    /**
     * Seed optional starter data for shipping workflows.
     */
    public function run(): void
    {
        $this->call([
            BoxSizeSeeder::class,
            ShippingMethodSeeder::class,
        ]);
    }
}
