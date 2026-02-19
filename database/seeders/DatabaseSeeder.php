<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'password' => 'admin',
            'role' => 'admin',
        ]);

        User::create([
            'name' => 'Manager',
            'username' => 'manager',
            'password' => 'manager',
            'role' => 'manager',
        ]);

        User::create([
            'name' => 'Test User',
            'username' => 'user',
            'password' => 'user',
            'role' => 'user',
        ]);

        $this->call([
            SettingsSeeder::class,
            ChannelSeeder::class,
            CarrierSeeder::class,
            BoxSizeSeeder::class,
            ShippingMethodSeeder::class,
            ShipmentSeeder::class,
        ]);

        if (class_exists(\Database\Seeders\LocalSettingsSeeder::class)) {
            $this->call(LocalSettingsSeeder::class);
        }
    }
}
