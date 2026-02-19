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
                'value' => 'Shipping',
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.last_name',
                'value' => 'Center',
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.company',
                'value' => '',
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.street',
                'value' => '',
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.street2',
                'value' => '',
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.city',
                'value' => '',
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.state_or_province',
                'value' => '',
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.postal_code',
                'value' => '',
                'type' => 'string',
                'group' => 'from_address',
            ],
            [
                'key' => 'from_address.phone',
                'value' => '',
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
            [
                'key' => 'transparency_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'features',
            ],

            // Carrier API
            [
                'key' => 'carrier_api_timeout',
                'value' => '15',
                'type' => 'integer',
                'group' => 'carrier',
            ],

            // USPS Credentials
            [
                'key' => 'usps.client_id',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'usps',
            ],
            [
                'key' => 'usps.client_secret',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'usps',
            ],
            [
                'key' => 'usps.crid',
                'value' => '',
                'type' => 'string',
                'group' => 'usps',
            ],
            [
                'key' => 'usps.mid',
                'value' => '',
                'type' => 'string',
                'group' => 'usps',
            ],

            // FedEx Credentials
            [
                'key' => 'fedex.api_key',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'fedex',
            ],
            [
                'key' => 'fedex.api_secret',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'fedex',
            ],
            [
                'key' => 'fedex.account_number',
                'value' => '',
                'type' => 'string',
                'group' => 'fedex',
            ],

            // UPS Credentials
            [
                'key' => 'ups.client_id',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'ups',
            ],
            [
                'key' => 'ups.client_secret',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'ups',
            ],
            [
                'key' => 'ups.account_number',
                'value' => '',
                'type' => 'string',
                'group' => 'ups',
            ],

            // Shopify Credentials
            [
                'key' => 'shopify.shop_domain',
                'value' => '',
                'type' => 'string',
                'group' => 'shopify',
            ],
            [
                'key' => 'shopify.client_id',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'shopify',
            ],
            [
                'key' => 'shopify.client_secret',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'shopify',
            ],
            [
                'key' => 'shopify.api_version',
                'value' => '2025-01',
                'type' => 'string',
                'group' => 'shopify',
            ],

            // Amazon Credentials
            [
                'key' => 'amazon.client_id',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'amazon',
            ],
            [
                'key' => 'amazon.client_secret',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'amazon',
            ],
            [
                'key' => 'amazon.refresh_token',
                'value' => '',
                'type' => 'string',
                'encrypted' => true,
                'group' => 'amazon',
            ],
            [
                'key' => 'amazon.marketplace_id',
                'value' => 'ATVPDKIKX0DER',
                'type' => 'string',
                'group' => 'amazon',
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
