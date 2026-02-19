<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ChannelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //

        \App\Models\Channel::create([
            'name' => 'Test Channel',
            'channel_reference' => 'TEST',
            'active' => true,
        ]);

        \App\Models\Channel::create([
            'name' => 'Amazon US',
            'channel_reference' => 'AMAZON_US',
            'active' => true,
        ]);

        \App\Models\Channel::create([
            'name' => 'Shopify Store',
            'channel_reference' => 'SHOPIFY',
            'active' => true,
        ]);

    }
}
