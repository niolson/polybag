<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

class ShipmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure we have a channel
        $channel = Channel::firstOrCreate(
            ['channel_reference' => 'TEST'],
            ['name' => 'Test Channel', 'active' => true]
        );

        $shippingMethod = ShippingMethod::first();

        $shipments = [
            // ===== VALID ADDRESSES - Should validate exactly =====
            [
                'shipment_reference' => 'TEST-001',
                'first_name' => 'John',
                'last_name' => 'Smith',
                'company' => 'The White House',
                'address1' => '1600 Pennsylvania Avenue NW',
                'city' => 'Washington',
                'state_or_province' => 'DC',
                'postal_code' => '20500',
                'country' => 'US',
                'phone' => '202-456-1111',
                'residential' => false,
                'value' => 99.99,
                'notes' => 'Valid: Exact address (commercial)',
                'items' => [
                    ['description' => 'Widget A', 'quantity' => 2, 'value' => 49.99],
                ],
            ],
            [
                'shipment_reference' => 'TEST-002',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'company' => 'Empire State Building',
                'address1' => '350 5th Avenue',
                'city' => 'New York',
                'state_or_province' => 'NY',
                'postal_code' => '10118',
                'country' => 'US',
                'phone' => '212-736-3100',
                'residential' => false,
                'value' => 149.99,
                'notes' => 'Valid: Exact address (commercial)',
                'items' => [
                    ['description' => 'Gadget B', 'quantity' => 1, 'value' => 149.99],
                ],
            ],
            [
                'shipment_reference' => 'TEST-003',
                'first_name' => 'Bob',
                'last_name' => 'Johnson',
                'company' => 'Apple Park',
                'address1' => '1 Apple Park Way',
                'city' => 'Cupertino',
                'state_or_province' => 'CA',
                'postal_code' => '95014',
                'country' => 'US',
                'phone' => '408-996-1010',
                'residential' => false,
                'value' => 299.99,
                'notes' => 'Valid: Exact address (commercial)',
                'items' => [
                    ['description' => 'Device C', 'quantity' => 1, 'value' => 199.99],
                    ['description' => 'Accessory D', 'quantity' => 2, 'value' => 50.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-004',
                'first_name' => 'Alice',
                'last_name' => 'Williams',
                'company' => 'Space Needle',
                'address1' => '400 Broad Street',
                'city' => 'Seattle',
                'state_or_province' => 'WA',
                'postal_code' => '98109',
                'country' => 'US',
                'phone' => '206-905-2100',
                'residential' => false,
                'value' => 75.00,
                'notes' => 'Valid: Exact address (commercial)',
                'items' => [
                    ['description' => 'Souvenir E', 'quantity' => 3, 'value' => 25.00],
                ],
            ],

            // ===== NON-CONTINENTAL US ADDRESSES =====
            [
                'shipment_reference' => 'TEST-005',
                'first_name' => 'Leilani',
                'last_name' => 'Kahale',
                'company' => 'Iolani Palace',
                'address1' => '364 S King St',
                'city' => 'Honolulu',
                'state_or_province' => 'HI',
                'postal_code' => '96813',
                'country' => 'US',
                'phone' => '808-522-0822',
                'value' => 125.00,
                'notes' => 'Valid: Hawaii address',
                'items' => [
                    ['description' => 'Hawaiian Gift', 'quantity' => 1, 'value' => 125.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-006',
                'first_name' => 'Kodiak',
                'last_name' => 'Denali',
                'company' => 'Anchorage Museum',
                'address1' => '625 C St',
                'city' => 'Anchorage',
                'state_or_province' => 'AK',
                'postal_code' => '99501',
                'country' => 'US',
                'phone' => '907-929-9200',
                'value' => 200.00,
                'notes' => 'Valid: Alaska address',
                'items' => [
                    ['description' => 'Alaskan Artifact', 'quantity' => 2, 'value' => 100.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-007',
                'first_name' => 'Carlos',
                'last_name' => 'Rivera',
                'company' => 'El Morro',
                'address1' => '501 Calle Norzagaray',
                'city' => 'San Juan',
                'state_or_province' => 'PR',
                'postal_code' => '00901',
                'country' => 'US',
                'phone' => '787-729-6960',
                'value' => 95.00,
                'notes' => 'Valid: Puerto Rico address',
                'items' => [
                    ['description' => 'Puerto Rico Souvenir', 'quantity' => 1, 'value' => 95.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-008',
                'first_name' => 'Charlotte',
                'last_name' => 'Amalie',
                'company' => 'Virgin Islands NP',
                'address1' => '1300 Cruz Bay Creek',
                'city' => 'St John',
                'state_or_province' => 'VI',
                'postal_code' => '00830',
                'country' => 'US',
                'phone' => '340-776-6201',
                'value' => 150.00,
                'notes' => 'Valid: US Virgin Islands address',
                'items' => [
                    ['description' => 'Island Craft', 'quantity' => 1, 'value' => 150.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-009',
                'first_name' => 'SGT Michael',
                'last_name' => 'Thompson',
                'company' => 'Unit 12345',
                'address1' => 'PSC 123 Box 4567',
                'city' => 'APO',
                'state_or_province' => 'AP',
                'postal_code' => '96266',
                'country' => 'US',
                'phone' => '555-012-3456',
                'value' => 75.00,
                'notes' => 'Valid: Armed Forces Pacific (AP) military address',
                'items' => [
                    ['description' => 'Care Package', 'quantity' => 1, 'value' => 75.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-009B',
                'first_name' => 'CPT Jessica',
                'last_name' => 'Martinez',
                'company' => 'Unit 98765',
                'address1' => 'CMR 456 Box 789',
                'city' => 'APO',
                'state_or_province' => 'AE',
                'postal_code' => '09012',
                'country' => 'US',
                'phone' => '555-098-7654',
                'value' => 85.00,
                'notes' => 'Valid: Armed Forces Europe (AE) military address',
                'items' => [
                    ['description' => 'Military Supply', 'quantity' => 1, 'value' => 85.00],
                ],
            ],

            // ===== CORRECTABLE ADDRESSES - Minor issues that USPS can fix =====
            [
                'shipment_reference' => 'TEST-010',
                'first_name' => 'Mike',
                'last_name' => 'Brown',
                'company' => '',
                'address1' => '350 Fifth Avenue',  // Written out vs "5th"
                'city' => 'New York',
                'state_or_province' => 'NY',
                'postal_code' => '10001',  // Wrong zip (should be 10118)
                'country' => 'US',
                'phone' => '212-555-0101',
                'residential' => true,
                'value' => 50.00,
                'notes' => 'Correctable: Wrong ZIP code (residential)',
                'items' => [
                    ['description' => 'Book F', 'quantity' => 1, 'value' => 50.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-011',
                'first_name' => 'Sarah',
                'last_name' => 'Davis',
                'company' => 'Google',
                'address1' => '1600 Amphitheatre Pkwy',  // Missing "Parkway" full spelling
                'city' => 'Mountain View',
                'state_or_province' => 'CA',
                'postal_code' => '94043',
                'country' => 'US',
                'phone' => '650-253-0000',
                'residential' => false,
                'value' => 125.00,
                'notes' => 'Correctable: Abbreviated street type (commercial)',
                'items' => [
                    ['description' => 'Tech Item G', 'quantity' => 1, 'value' => 125.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-012',
                'first_name' => 'Tom',
                'last_name' => 'Wilson',
                'company' => '',
                'address1' => '1 Infinite Loop',
                'city' => 'Cupertino',
                'state_or_province' => 'California',  // Full state name instead of abbreviation
                'postal_code' => '95014',
                'country' => 'US',
                'phone' => '408-555-0102',
                'residential' => false,
                'value' => 200.00,
                'notes' => 'Correctable: Full state name (commercial)',
                'items' => [
                    ['description' => 'Hardware H', 'quantity' => 1, 'value' => 200.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-013',
                'first_name' => 'Emma',
                'last_name' => 'Taylor',
                'company' => 'Willis Tower',
                'address1' => '233 S Wacker Dr',
                'city' => 'Chicago',
                'state_or_province' => 'IL',
                'postal_code' => '60606',  // Close but might need correction
                'country' => 'US',
                'phone' => '312-875-9447',
                'value' => 85.00,
                'notes' => 'Correctable: Abbreviated direction and street type',
                'items' => [
                    ['description' => 'Office Supply I', 'quantity' => 5, 'value' => 17.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-014',
                'first_name' => 'Chris',
                'last_name' => 'Anderson',
                'company' => '',
                'address1' => '1600 pennsylvania ave nw',  // All lowercase
                'city' => 'washington',
                'state_or_province' => 'dc',
                'postal_code' => '20500',
                'country' => 'US',
                'phone' => '202-555-0140',
                'value' => 60.00,
                'notes' => 'Correctable: All lowercase',
                'items' => [
                    ['description' => 'Gift J', 'quantity' => 2, 'value' => 30.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-015',
                'first_name' => 'Lisa',
                'last_name' => 'Martin',
                'company' => 'Statue of Liberty',
                'address1' => 'Liberty Island',  // No street number
                'city' => 'New York',
                'state_or_province' => 'NY',
                'postal_code' => '10004',
                'country' => 'US',
                'phone' => '212-363-3200',
                'value' => 45.00,
                'notes' => 'Correctable: Landmark address',
                'items' => [
                    ['description' => 'Memorabilia K', 'quantity' => 1, 'value' => 45.00],
                ],
            ],

            // ===== AMBIGUOUS ADDRESSES - Multiple matches possible =====
            [
                'shipment_reference' => 'TEST-020',
                'first_name' => 'David',
                'last_name' => 'Miller',
                'company' => '',
                'address1' => '123 Main Street',  // Very common street name
                'city' => 'Springfield',  // Common city name, exists in many states
                'state_or_province' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
                'phone' => '217-555-0200',
                'residential' => true,
                'value' => 30.00,
                'notes' => 'Ambiguous: Common street/city name (residential)',
                'items' => [
                    ['description' => 'Item L', 'quantity' => 1, 'value' => 30.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-021',
                'first_name' => 'Nancy',
                'last_name' => 'Garcia',
                'company' => '',
                'address1' => '100 Broadway',  // Common street, no direction
                'city' => 'New York',
                'state_or_province' => 'NY',
                'postal_code' => '10005',
                'country' => 'US',
                'phone' => '212-555-0210',
                'residential' => true,
                'value' => 55.00,
                'notes' => 'Ambiguous: Common street, no direction (residential)',
                'items' => [
                    ['description' => 'Item M', 'quantity' => 1, 'value' => 55.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-022',
                'first_name' => 'Kevin',
                'last_name' => 'Lee',
                'company' => '',
                'address1' => '500 Market St',  // Exists in multiple cities
                'address2' => 'Suite 100',
                'city' => 'San Francisco',
                'state_or_province' => 'CA',
                'postal_code' => '94102',  // Might match multiple buildings
                'country' => 'US',
                'phone' => '415-555-0220',
                'residential' => false,
                'value' => 175.00,
                'notes' => 'Ambiguous: Large building, suite might not validate (commercial)',
                'items' => [
                    ['description' => 'Equipment N', 'quantity' => 1, 'value' => 175.00],
                ],
            ],

            // ===== INVALID ADDRESSES - Should fail validation =====
            [
                'shipment_reference' => 'TEST-030',
                'first_name' => 'Fake',
                'last_name' => 'Person',
                'company' => '',
                'address1' => '99999 Nonexistent Boulevard',
                'city' => 'Faketown',
                'state_or_province' => 'ZZ',  // Invalid state
                'postal_code' => '00000',
                'country' => 'US',
                'phone' => '555-000-0000',
                'value' => 10.00,
                'notes' => 'Invalid: Completely fake address',
                'items' => [
                    ['description' => 'Fake Item', 'quantity' => 1, 'value' => 10.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-031',
                'first_name' => 'Test',
                'last_name' => 'User',
                'company' => '',
                'address1' => '123 This Street Does Not Exist',
                'city' => 'Los Angeles',
                'state_or_province' => 'CA',
                'postal_code' => '90001',
                'country' => 'US',
                'phone' => '310-555-0310',
                'value' => 25.00,
                'notes' => 'Invalid: Fake street in real city',
                'items' => [
                    ['description' => 'Test Product', 'quantity' => 1, 'value' => 25.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-032',
                'first_name' => 'Wrong',
                'last_name' => 'postal_code',
                'company' => '',
                'address1' => '1600 Pennsylvania Avenue NW',
                'city' => 'Los Angeles',  // Wrong city for this address
                'state_or_province' => 'CA',  // Wrong state
                'postal_code' => '90001',  // Wrong zip
                'country' => 'US',
                'phone' => '310-555-0320',
                'value' => 35.00,
                'notes' => 'Invalid: Real street, wrong city/state/zip',
                'items' => [
                    ['description' => 'Misrouted Item', 'quantity' => 1, 'value' => 35.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-033',
                'first_name' => 'Missing',
                'last_name' => 'Info',
                'company' => '',
                'address1' => 'Apartment 5B',  // Missing street
                'city' => 'Boston',
                'state_or_province' => 'MA',
                'postal_code' => '02101',
                'country' => 'US',
                'phone' => '617-555-0330',
                'value' => 40.00,
                'notes' => 'Invalid: Missing street address',
                'items' => [
                    ['description' => 'Incomplete Order', 'quantity' => 1, 'value' => 40.00],
                ],
            ],

            // ===== INTERNATIONAL ADDRESSES =====
            [
                'shipment_reference' => 'TEST-040',
                'first_name' => 'Pierre',
                'last_name' => 'Dubois',
                'company' => 'Tour Eiffel',
                'address1' => 'Champ de Mars, 5 Avenue Anatole France',
                'city' => 'Paris',
                'state_or_province' => '',
                'postal_code' => '75007',
                'country' => 'FR',
                'phone' => '+33-1-44-11-23-23',
                'value' => 250.00,
                'notes' => 'International: France',
                'items' => [
                    ['description' => 'Export Item O', 'quantity' => 1, 'value' => 250.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-041',
                'first_name' => 'James',
                'last_name' => 'Bond',
                'company' => 'MI6',
                'address1' => '85 Albert Embankment',
                'city' => 'London',
                'state_or_province' => '',
                'postal_code' => 'SE1 7TP',
                'country' => 'GB',
                'phone' => '+44-20-7555-0041',
                'value' => 300.00,
                'notes' => 'International: United Kingdom',
                'items' => [
                    ['description' => 'Export Item P', 'quantity' => 2, 'value' => 150.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-042',
                'first_name' => 'Yuki',
                'last_name' => 'Tanaka',
                'company' => 'Tokyo Tower',
                'address1' => '4 Chome-2-8 Shibakoen',
                'city' => 'Minato City',
                'state_or_province' => 'Tokyo',
                'postal_code' => '105-0011',
                'country' => 'JP',
                'phone' => '+81-3-3433-5111',
                'value' => 400.00,
                'notes' => 'International: Japan',
                'items' => [
                    ['description' => 'Export Item Q', 'quantity' => 1, 'value' => 400.00],
                ],
            ],
            [
                'shipment_reference' => 'TEST-043',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'company' => '',
                'address1' => '123 Avenida Paulista',
                'city' => 'Sao Paulo',
                'state_or_province' => 'SP',
                'postal_code' => '01310-100',
                'country' => 'BR',
                'phone' => '+55-11-5555-0430',
                'value' => 150.00,
                'notes' => 'International: Brazil',
                'items' => [
                    ['description' => 'Export Item R', 'quantity' => 3, 'value' => 50.00],
                ],
            ],
        ];

        foreach ($shipments as $shipmentData) {
            $items = $shipmentData['items'] ?? [];
            unset($shipmentData['items'], $shipmentData['notes']);

            $shipment = Shipment::firstOrCreate(
                ['shipment_reference' => $shipmentData['shipment_reference']],
                [
                    ...$shipmentData,
                    'shipping_method_id' => $shippingMethod?->id,
                    'channel_id' => $channel->id,
                ],
            );

            if (! $shipment->wasRecentlyCreated) {
                continue;
            }

            foreach ($items as $item) {
                $sku = 'SKU-'.strtoupper(substr(md5($item['description']), 0, 8));

                $product = Product::firstOrCreate(
                    ['sku' => $sku],
                    [
                        'name' => $item['description'],
                        'barcode' => $sku,
                        'description' => $item['description'],
                        'active' => true,
                    ]
                );

                $shipment->shipmentItems()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'value' => $item['value'],
                ]);
            }
        }
    }
}
