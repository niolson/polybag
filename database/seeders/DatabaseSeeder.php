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

        \App\Models\User::factory()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'password' => 'admin',  // Auto-hashed by User model cast
            'role' => 'admin',
        ]);

        \App\Models\User::factory()->create([
            'name' => 'Manager',
            'username' => 'manager',
            'password' => 'manager',  // Auto-hashed by User model cast
            'role' => 'manager',
        ]);

        \App\Models\User::factory()->create([
            'name' => 'Test User',
            'username' => 'user',
            'password' => 'user',  // Auto-hashed by User model cast
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
    }
}
