<?php

namespace Database\Seeders;

use App\Models\Channel;
use Illuminate\Database\Seeder;

class ChannelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Channel::firstOrCreate(
            ['name' => 'Test Channel'],
            ['active' => true],
        );

        $this->call(StoreChannelSeeder::class);
    }
}
