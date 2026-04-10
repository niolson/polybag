<?php

namespace Database\Seeders;

use App\Enums\SpecialServiceScope;
use App\Models\SpecialService;
use Illuminate\Database\Seeder;

class SpecialServiceSeeder extends Seeder
{
    /**
     * Seed the special services catalog.
     *
     * This is a code-owned registry — rows are managed here, not via admin UI.
     * Use the `active` flag to deprecate services without deleting them.
     */
    public function run(): void
    {
        $services = [
            // --- Delivery ---
            [
                'code' => 'signature_required',
                'name' => 'Signature Required',
                'description' => 'Recipient must sign for the package.',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'delivery',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'adult_signature_required',
                'name' => 'Adult Signature Required',
                'description' => 'Recipient must be 21+ and sign for the package.',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'delivery',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'saturday_delivery',
                'name' => 'Saturday Delivery',
                'description' => 'Request delivery on Saturday where available.',
                'scope' => SpecialServiceScope::Shipment->value,
                'category' => 'delivery',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'evening_delivery',
                'name' => 'Evening Delivery',
                'description' => 'FedEx Home Delivery evening delivery option.',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'delivery',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'carrier_release',
                'name' => 'Carrier Release',
                'description' => 'Authorize the carrier to leave the package without a signature.',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'delivery',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'declared_value',
                'name' => 'Declared Value',
                'description' => 'Declare a value for insurance/liability coverage.',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'delivery',
                'requires_value' => true,
                'config_schema' => ['amount' => 'numeric', 'currency' => 'string'],
            ],

            // --- Pickup ---
            [
                'code' => 'hold_at_location',
                'name' => 'Hold at Location',
                'description' => 'Hold the package at a carrier facility for recipient pickup.',
                'scope' => SpecialServiceScope::Shipment->value,
                'category' => 'pickup',
                'requires_value' => true,
                'config_schema' => ['location_id' => 'string', 'address' => 'object'],
            ],

            // --- Notifications ---
            [
                'code' => 'email_notification',
                'name' => 'Email Notification',
                'description' => 'Send shipment event notifications to one or more email addresses.',
                'scope' => SpecialServiceScope::Shipment->value,
                'category' => 'notifications',
                'requires_value' => true,
                'config_schema' => ['emails' => 'array'],
            ],

            // --- Compliance ---
            [
                'code' => 'alcohol',
                'name' => 'Alcohol',
                'description' => 'Shipment contains alcoholic beverages. Requires adult signature at delivery.',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'compliance',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'dry_ice',
                'name' => 'Dry Ice',
                'description' => 'Shipment contains dry ice as a refrigerant.',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'compliance',
                'requires_value' => true,
                'config_schema' => ['weight_kg' => 'numeric'],
            ],
            [
                'code' => 'lithium_battery_in_equipment',
                'name' => 'Lithium Battery (in equipment)',
                'description' => 'Contains lithium batteries installed in or packed with equipment (UN3481/UN3091).',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'compliance',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'lithium_battery_standalone',
                'name' => 'Lithium Battery (standalone)',
                'description' => 'Contains standalone lithium batteries not installed in equipment (UN3480/UN3090).',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'compliance',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'lithium_battery_ground_only',
                'name' => 'Lithium Battery (ground only)',
                'description' => 'Contains lithium batteries restricted to ground transport only.',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'compliance',
                'requires_value' => false,
                'config_schema' => null,
            ],
            [
                'code' => 'cremated_remains',
                'name' => 'Cremated Remains',
                'description' => 'Shipment contains cremated human remains (USPS only).',
                'scope' => SpecialServiceScope::Package->value,
                'category' => 'compliance',
                'requires_value' => false,
                'config_schema' => null,
            ],
        ];

        foreach ($services as $service) {
            SpecialService::updateOrCreate(
                ['code' => $service['code']],
                array_merge($service, [
                    'config_schema' => $service['config_schema'] ? json_encode($service['config_schema']) : null,
                    'active' => true,
                ]),
            );
        }
    }
}
