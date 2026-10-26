<?php

return [

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

        // PHP memory_limit an import run sets, whatever the worker was started
        // with; the worker keeps it afterwards. Null leaves the limit alone.
        'memory_limit' => env('SHIPMENT_IMPORT_MEMORY_LIMIT', '256M'),

        // The most rows one run reads from a Database connection's shipments query.
        // A run holds one page of rows plus every row's ID, so this bounds the IDs
        // and catches a query that forgot its status filter. 0 means no limit.
        'max_database_rows' => (int) env('SHIPMENT_IMPORT_MAX_DATABASE_ROWS', 250000),

        // The most orders one run reads from Amazon, open and closed together. A run
        // holds every order it reads, about 28 KB each for orders of three items, so
        // this bounds its memory. A read cut short keeps
        // its pagination token and the next run carries on, as when Amazon throttles it.
        // 0 means no limit.
        'max_amazon_orders_per_run' => (int) env('SHIPMENT_IMPORT_MAX_AMAZON_ORDERS_PER_RUN', 3000),
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
