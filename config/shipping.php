<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Origin ZIP Code
    |--------------------------------------------------------------------------
    |
    | The ZIP code where shipments originate from.
    |
    */
    'origin_zip' => env('SHIPPING_ORIGIN_ZIP', '98072'),

    /*
    |--------------------------------------------------------------------------
    | From Address
    |--------------------------------------------------------------------------
    |
    | The return/from address used on shipping labels.
    |
    */
    'from_address' => [
        'first_name' => env('SHIPPING_FROM_FIRST_NAME', 'Shipping'),
        'last_name' => env('SHIPPING_FROM_LAST_NAME', 'Center'),
        'company' => env('SHIPPING_FROM_COMPANY', ''),
        'street' => env('SHIPPING_FROM_STREET', ''),
        'street2' => env('SHIPPING_FROM_STREET2', ''),
        'city' => env('SHIPPING_FROM_CITY', ''),
        'state' => env('SHIPPING_FROM_STATE', ''),
        'zip_plus4' => env('SHIPPING_FROM_ZIP_PLUS4', ''),
        'phone' => env('SHIPPING_FROM_PHONE', ''),
    ],

];
