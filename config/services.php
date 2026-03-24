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

    'ups' => [
        'base_url' => env('UPS_API_BASE_URL', 'https://onlinetools.ups.com'),
        'sandbox_url' => env('UPS_API_SANDBOX_URL', 'https://wwwcie.ups.com'),
        'client_id' => env('UPS_CLIENT_ID'),
        'client_secret' => env('UPS_CLIENT_SECRET'),
        'account_number' => env('UPS_ACCOUNT_NUMBER'),
    ],

    'shopify' => [
        'shop_domain' => env('SHOPIFY_SHOP_DOMAIN'),
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
    ],

    'oauth' => [
        'broker_url' => env('OAUTH_BROKER_URL'),
        'broker_secret' => env('OAUTH_BROKER_SECRET'),
        'instance_id' => env('OAUTH_INSTANCE_ID'),
    ],

    'amazon' => [
        'base_url' => env('AMAZON_SP_API_BASE_URL', 'https://sellingpartnerapi-na.amazon.com'),
        'sandbox_url' => env('AMAZON_SP_API_SANDBOX_URL', 'https://sandbox.sellingpartnerapi-na.amazon.com'),
        'client_id' => env('AMAZON_SP_API_CLIENT_ID'),
        'client_secret' => env('AMAZON_SP_API_CLIENT_SECRET'),
        'refresh_token' => env('AMAZON_SP_API_REFRESH_TOKEN'),
        'marketplace_id' => env('AMAZON_SP_API_MARKETPLACE_ID', 'ATVPDKIKX0DER'),
    ],

];
