<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Import Source
    |--------------------------------------------------------------------------
    |
    | The default source to use when running the import command without
    | specifying a source.
    |
    */
    'default' => env('SHIPMENT_IMPORT_SOURCE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Import Sources
    |--------------------------------------------------------------------------
    |
    | Define all available import sources here. Each source can have its
    | own connection settings and field mappings.
    |
    */
    'sources' => [

        'database' => [
            'driver' => \App\Services\ShipmentImport\Sources\DatabaseSource::class,
            'connection' => env('SHIPMENT_IMPORT_DB_CONNECTION', 'import'),
            'enabled' => env('SHIPMENT_IMPORT_DATABASE_ENABLED', true),

            // Table names
            'shipments_table' => env('SHIPMENT_IMPORT_SHIPMENTS_TABLE', 'shipments'),
            'shipment_items_table' => env('SHIPMENT_IMPORT_ITEMS_TABLE', 'shipment_items'),

            // Optional: Custom SQL queries (set to null to use table-based queries)
            // Use :shipment_reference as placeholder for item queries
            'shipments_query' => env('SHIPMENT_IMPORT_SHIPMENTS_QUERY'),
            'shipment_items_query' => env('SHIPMENT_IMPORT_ITEMS_QUERY'),

            // Filter criteria for table-based queries
            'filters' => [
                // 'status' => ['ready', 'pending'],
            ],

            // Mark shipments as exported on the external database after import
            // Uses :shipment_reference as placeholder for the shipment identifier
            'mark_exported' => [
                'enabled' => env('SHIPMENT_IMPORT_MARK_EXPORTED', false),
                'query' => env('SHIPMENT_IMPORT_MARK_EXPORTED_QUERY', "update shipments set exported = 'y' where id = :shipment_reference"),
            ],

            // Field mappings: external_field => internal_field
            'field_mapping' => [
                'shipment' => [
                    'id' => 'shipment_reference',
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'company' => 'company',
                    'address1' => 'address1',
                    'address2' => 'address2',
                    'city' => 'city',
                    'state' => 'state',
                    'zip' => 'zip',
                    'country' => 'country',
                    'phone' => 'phone',
                    'phone_extension' => 'phone_extension',
                    'email' => 'email',
                    'value' => 'value',
                    'shipping_method' => 'shipping_method_id',
                    'channel' => 'channel_id',
                ],
                'shipment_item' => [
                    'sku' => 'sku',
                    'name' => 'name',
                    'description' => 'description',
                    'barcode' => 'barcode',
                    'quantity' => 'quantity',
                    'weight' => 'weight',
                    'value' => 'value',
                    'transparency' => 'transparency',
                ],
            ],
        ],

        // Future: Shopify GraphQL source
        // 'shopify' => [
        //     'driver' => \App\Services\ShipmentImport\Sources\ShopifySource::class,
        //     'enabled' => env('SHIPMENT_IMPORT_SHOPIFY_ENABLED', false),
        //     'shop_domain' => env('SHOPIFY_SHOP_DOMAIN'),
        //     'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
        //     'api_version' => '2024-01',
        //     'field_mapping' => [...],
        // ],

        // Future: Amazon SP-API source
        // 'amazon' => [
        //     'driver' => \App\Services\ShipmentImport\Sources\AmazonSource::class,
        //     'enabled' => env('SHIPMENT_IMPORT_AMAZON_ENABLED', false),
        //     'field_mapping' => [...],
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Import Behavior
    |--------------------------------------------------------------------------
    */
    'behavior' => [
        // Auto-create/update products
        'auto_update_products' => env('SHIPMENT_IMPORT_AUTO_UPDATE_PRODUCTS', true),

        // Batch size for processing (number of shipments per transaction)
        'batch_size' => env('SHIPMENT_IMPORT_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('SHIPMENT_IMPORT_LOG_CHANNEL', 'shipment-import'),
    ],

];
