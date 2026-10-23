<?php

namespace Database\Seeders;

use App\Models\Channel;
use Illuminate\Database\Seeder;

/**
 * One channel per kind of store a connection can import from, plus Manual.
 * `demo:provision` seeds these without `ChannelSeeder`'s `Test Channel`.
 */
class StoreChannelSeeder extends Seeder
{
    public const AMAZON = 'Amazon US';

    public const SHOPIFY = 'Shopify Store';

    public function run(): void
    {
        Channel::firstOrCreate(
            ['name' => self::AMAZON],
            ['active' => true, 'icon' => 'heroicon-o-shopping-bag'],
        );

        Channel::firstOrCreate(
            ['name' => self::SHOPIFY],
            ['active' => true, 'icon' => 'heroicon-o-shopping-cart'],
        );

        Channel::firstOrCreate(
            ['name' => 'Manual'],
            ['active' => true, 'icon' => 'heroicon-o-pencil-square'],
        );
    }
}
