<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'usps' => [
        'base_url' => env('USPS_API_BASE_URL', 'https://apis.usps.com'),
        'sandbox_url' => env('USPS_API_SANDBOX_URL', 'https://apis-tem.usps.com'),
        'client_id' => env('USPS_API_CLIENT_ID'),
        'client_secret' => env('USPS_API_CLIENT_SECRET'),
        'crid' => env('USPS_CRID'),
        'mid' => env('USPS_MID'),
    ],

    'fedex' => [
        'base_url' => env('FEDEX_API_BASE_URL', 'https://apis.fedex.com'),
        'sandbox_url' => env('FEDEX_API_SANDBOX_URL', 'https://apis-sandbox.fedex.com'),
        'api_key' => env('FEDEX_API_KEY'),
        'api_secret' => env('FEDEX_API_SECRET'),
        'account_number' => env('FEDEX_ACCOUNT_NUMBER'),
    ],

];
