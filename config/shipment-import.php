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
