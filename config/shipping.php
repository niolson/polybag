<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Origin Postal Code
    |--------------------------------------------------------------------------
    |
    | The postal code where shipments originate from.
    |
    */
    'origin_postal_code' => env('SHIPPING_ORIGIN_POSTAL_CODE', '98072'),

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
        'state' => env('SHIPPING_FROM_STATE_OR_PROVINCE', ''),
        'postal_code_plus4' => env('SHIPPING_FROM_POSTAL_CODE_PLUS4', ''),
        'phone' => env('SHIPPING_FROM_PHONE', ''),
    ],

];
