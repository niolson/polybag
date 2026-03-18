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

        User::firstOrCreate(['username' => 'admin'], [
            'name' => 'Admin',
            'password' => 'admin',
            'role' => 'admin',
        ]);

        User::firstOrCreate(['username' => 'manager'], [
            'name' => 'Manager',
            'password' => 'manager',
            'role' => 'manager',
        ]);

        User::firstOrCreate(['username' => 'user'], [
            'name' => 'Test User',
            'password' => 'user',
            'role' => 'user',
        ]);

        $this->call([
            SettingsSeeder::class,
            LocationSeeder::class,
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
