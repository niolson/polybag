<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Read existing from_address settings to seed the default location
        $settings = DB::table('settings')
            ->where('key', 'like', 'from_address.%')
            ->pluck('value', 'key');

        if ($settings->isEmpty()) {
            return;
        }

        DB::table('locations')->insert([
            'name' => 'Main Warehouse',
            'company' => $settings->get('from_address.company'),
            'first_name' => $settings->get('from_address.first_name', 'Shipping'),
            'last_name' => $settings->get('from_address.last_name', 'Center'),
            'address1' => $settings->get('from_address.street', ''),
            'address2' => $settings->get('from_address.street2'),
            'city' => $settings->get('from_address.city', ''),
            'state_or_province' => $settings->get('from_address.state_or_province', ''),
            'postal_code' => $settings->get('from_address.postal_code', ''),
            'country' => 'US',
            'phone' => $settings->get('from_address.phone'),
            'is_default' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('locations')->where('name', 'Main Warehouse')->where('is_default', true)->delete();
    }
};
