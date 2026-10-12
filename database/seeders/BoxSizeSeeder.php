<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The optional starter Box Sizes the Setup Wizard and the operational defaults
 * load: the common stock sizes and the carrier packaging.
 */
class BoxSizeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            CommonBoxSizeSeeder::class,
            CarrierPackagingSeeder::class,
        ]);
    }
}
