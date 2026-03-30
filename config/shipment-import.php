<?php

use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;

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

        // Security: The import DB user should have only SELECT privileges.
        // If export is enabled, the export DB user needs UPDATE on the target table.
        'database' => [
            'driver' => DatabaseSource::class,
            'connection' => env('SHIPMENT_IMPORT_DB_CONNECTION', 'import'),
            'enabled' => env('SHIPMENT_IMPORT_DATABASE_ENABLED', true),

            // SSH tunnel: connect to the import DB through an SSH bastion host
            // remote_host/remote_port override what the tunnel connects to on the
            // remote side (defaults to DB host/port from the connection config).
            // Set remote_host to 127.0.0.1 when the DB runs on the SSH host itself.
            'ssh' => [
                'enabled' => env('SHIPMENT_IMPORT_SSH_ENABLED', false),
                'host' => env('SHIPMENT_IMPORT_SSH_HOST'),
                'port' => env('SHIPMENT_IMPORT_SSH_PORT', 22),
                'user' => env('SHIPMENT_IMPORT_SSH_USER'),
                'key' => env('SHIPMENT_IMPORT_SSH_KEY', storage_path('app/private/ssh/id_ed25519')),
                'remote_host' => env('SHIPMENT_IMPORT_SSH_REMOTE_HOST'),
                'remote_port' => env('SHIPMENT_IMPORT_SSH_REMOTE_PORT'),
            ],

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

            // Export: write package data back to the external database after shipping
            'export' => [
                'enabled' => env('SHIPMENT_EXPORT_DATABASE_ENABLED', false),
                'query' => env('SHIPMENT_EXPORT_QUERY', 'UPDATE orders SET tracking_number = :tracking_number WHERE id = :shipment_reference'),
                'field_mapping' => [
                    // internal_name => query_parameter_name
                    'tracking_number' => 'tracking_number',
                    'weight' => 'weight',
                    'height' => 'height',
                    'width' => 'width',
                    'length' => 'length',
                    'cost' => 'cost',
                    'carrier' => 'carrier',
                    'service' => 'service',
                    'shipment_reference' => 'shipment_reference',
                ],
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
                    'state' => 'state_or_province',
                    'zip' => 'postal_code',
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

        'shopify' => [
            'driver' => ShopifySource::class,
            'enabled' => env('SHIPMENT_IMPORT_SHOPIFY_ENABLED', false),
            'channel_name' => env('SHOPIFY_CHANNEL_NAME', 'Shopify'),
            'shipping_method' => env('SHOPIFY_SHIPPING_METHOD'),
            'notify_customer' => env('SHOPIFY_NOTIFY_CUSTOMER', false),

            'export' => [
                'enabled' => env('SHIPMENT_EXPORT_SHOPIFY_ENABLED', false),
                'field_mapping' => [
                    'tracking_number' => 'tracking_number',
                    'carrier' => 'carrier',
                    'shipment_reference' => 'shipment_reference',
                    'fulfillment_order_id' => 'fulfillment_order_id',
                ],
            ],
        ],

        'amazon' => [
            'driver' => AmazonSource::class,
            'enabled' => env('SHIPMENT_IMPORT_AMAZON_ENABLED', false),
            'channel_name' => env('AMAZON_CHANNEL_NAME', 'Amazon'),
            'shipping_method' => env('AMAZON_SHIPPING_METHOD'),
            'lookback_days' => env('AMAZON_IMPORT_LOOKBACK_DAYS', 30),
            'export' => [
                'enabled' => env('SHIPMENT_EXPORT_AMAZON_ENABLED', false),
                'field_mapping' => [
                    'tracking_number' => 'tracking_number',
                    'carrier' => 'carrier',
                    'shipment_reference' => 'shipment_reference',
                    'amazon_order_id' => 'amazon_order_id',
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Export Channel Map
    |--------------------------------------------------------------------------
    |
    | Maps channel name to array of source names for package export.
    | '*' is the default fallback for any channel not explicitly listed.
    |
    */
    'export_channel_map' => [
        'Amazon' => ['amazon'],
        'Shopify' => ['shopify'],
        '*' => ['database'],
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
