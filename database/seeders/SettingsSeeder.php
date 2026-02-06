<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // Company Info
            [
                'key' => 'company_name',
                'value' => config('app.name', 'Shipping Center'),
                'type' => 'string',
                'group' => 'company',
            ],

            // From Address
            [
                'key' => 'from_address.first_name',
                'value' => config('shipping.from_address.first_name', 'Shipping'),
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.last_name',
                'value' => config('shipping.from_address.last_name', 'Center'),
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.company',
                'value' => config('shipping.from_address.company', ''),
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.street',
                'value' => config('shipping.from_address.street', ''),
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.street2',
                'value' => config('shipping.from_address.street2', ''),
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.city',
                'value' => config('shipping.from_address.city', ''),
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.state',
                'value' => config('shipping.from_address.state', ''),
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.zip',
                'value' => config('shipping.origin_zip', '98072'),
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.phone',
                'value' => config('shipping.from_address.phone', ''),
                'type' => 'string',
                'group' => 'from_address',
            ],

            // Feature Toggles
            [
                'key' => 'packing_validation_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'features',
            ],

            // Testing
            [
                'key' => 'sandbox_mode',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'testing',
            ],
            [
                'key' => 'suppress_printing',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'testing',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
