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
        'base_url' => 'https://apis.usps.com',
        'sandbox_url' => 'https://apis-tem.usps.com',
        'client_id' => null,
        'client_secret' => null,
        'crid' => null,
        'mid' => null,
    ],

    'fedex' => [
        'base_url' => 'https://apis.fedex.com',
        'sandbox_url' => 'https://apis-sandbox.fedex.com',
        'document_base_url' => 'https://documentapi.prod.fedex.com',
        'document_sandbox_url' => 'https://documentapitest.prod.fedex.com/sandbox',
        'api_key' => null,
        'api_secret' => null,
        'sandbox_api_key' => env('FEDEX_SANDBOX_API_KEY'),
        'sandbox_api_secret' => env('FEDEX_SANDBOX_API_SECRET'),
        'account_number' => null,
    ],

    'ups' => [
        'base_url' => 'https://onlinetools.ups.com',
        'sandbox_url' => 'https://wwwcie.ups.com',
        'client_id' => null,
        'client_secret' => null,
        'account_number' => null,
    ],

    'shopify' => [
        'shop_domain' => null,
        'client_id' => null,
        'client_secret' => null,
        'api_version' => '2025-01',
    ],

    'oauth' => [
        'broker_url' => env('OAUTH_BROKER_URL'),
        'broker_secret' => env('OAUTH_BROKER_SECRET'),
        'instance_id' => env('OAUTH_INSTANCE_ID'),
    ],

    'amazon' => [
        'base_url' => 'https://sellingpartnerapi-na.amazon.com',
        'sandbox_url' => 'https://sandbox.sellingpartnerapi-na.amazon.com',
        'client_id' => null,
        'client_secret' => null,
        'refresh_token' => null,
        'marketplace_id' => 'ATVPDKIKX0DER',
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
    ],

];
