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
            ['channel_reference' => 'TEST'],
            ['name' => 'Test Channel', 'active' => true],
        );

        Channel::firstOrCreate(
            ['channel_reference' => 'AMAZON_US'],
            ['name' => 'Amazon US', 'active' => true, 'icon' => 'heroicon-o-shopping-bag'],
        );

        Channel::firstOrCreate(
            ['channel_reference' => 'SHOPIFY'],
            ['name' => 'Shopify Store', 'active' => true, 'icon' => 'heroicon-o-shopping-cart'],
        );

        Channel::firstOrCreate(
            ['channel_reference' => 'MANUAL'],
            ['name' => 'Manual Entry', 'active' => true, 'icon' => 'heroicon-o-pencil-square'],
        );
    }
}
