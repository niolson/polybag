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

        Channel::firstOrCreate(
            ['name' => 'Amazon US'],
            ['active' => true, 'icon' => 'heroicon-o-shopping-bag'],
        );

        Channel::firstOrCreate(
            ['name' => 'Shopify Store'],
            ['active' => true, 'icon' => 'heroicon-o-shopping-cart'],
        );

        Channel::firstOrCreate(
            ['name' => 'Manual'],
            ['active' => true, 'icon' => 'heroicon-o-pencil-square'],
        );
    }
}
