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

    // Carrier and import credentials live on the CarrierAccount / DataSource models,
    // not here. Only non-credential infrastructure (API base URLs, default API
    // version) remains in config.
    'usps' => [
        'base_url' => 'https://apis.usps.com',
        'sandbox_url' => 'https://apis-tem.usps.com',
    ],

    'fedex' => [
        'base_url' => 'https://apis.fedex.com',
        'sandbox_url' => 'https://apis-sandbox.fedex.com',
        'document_base_url' => 'https://documentapi.prod.fedex.com',
        'document_sandbox_url' => 'https://documentapitest.prod.fedex.com/sandbox',
        // Ask FedEx as well when another validator settles an address it may
        // validate, and log the answer without using it
        // (`address-validation-routing/10`). Data collection for our hosted
        // deployment; off for everyone else.
        'shadow_address_validation' => (bool) env('ADDRESS_VALIDATION_FEDEX_SHADOW', false),
    ],

    'ups' => [
        'base_url' => 'https://onlinetools.ups.com',
        'sandbox_url' => 'https://wwwcie.ups.com',
    ],

    'shopify' => [
        'api_version' => '2026-07',
        // Shopify buys shipping labels asynchronously, so a purchase is polled
        // until it settles. The product of these two is how long a packer waits
        // at the Ship page before the purchase is reported as still running.
        'label_poll_attempts' => env('SHOPIFY_LABEL_POLL_ATTEMPTS', 20),
        'label_poll_interval_ms' => env('SHOPIFY_LABEL_POLL_INTERVAL_MS', 1500),
        // How far back to keep asking Shopify whether a label was voided.
        // Carriers stop accepting voids well inside this window, and PolyBag
        // never learns these packages were delivered — Shopify Shipping labels
        // carry no tracking updates — so without a bound the poll would grow to
        // cover every Shopify label ever bought.
        'label_void_check_days' => env('SHOPIFY_LABEL_VOID_CHECK_DAYS', 30),
        // How far back to keep looking for a label's price in its order
        // timeline. The event is written at purchase, so a label not priced
        // within this window never will be; it bounds the hourly poll for
        // labels priced in a currency PolyBag does not record.
        'label_cost_check_days' => env('SHOPIFY_LABEL_COST_CHECK_DAYS', 7),
    ],

    'oauth' => [
        'broker_url' => env('OAUTH_BROKER_URL'),
        'broker_secret' => env('OAUTH_BROKER_SECRET'),
        'instance_id' => env('OAUTH_INSTANCE_ID'),
        'bypass_broker' => env('OAUTH_BYPASS_BROKER', false),
    ],

    'amazon' => [
        'base_url' => 'https://sellingpartnerapi-na.amazon.com',
        // The default sandbox host only. Amazon scopes sandbox test cases by region
        // and the APIs we call disagree about which one they need, so a request that
        // needs a different host says so itself — see the Amazon
        // DeclaresSandboxRegion contract, which records why Orders v2026 is FE.
        'sandbox_url' => 'https://sandbox.sellingpartnerapi-na.amazon.com',
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
    ],

    // Separate from the 'google' SSO block above — a Google Cloud API key for the
    // Address Validation API, used to call Google directly. Hosted installs proxy
    // through the OAuth broker instead and leave this unset; self-hosted installs
    // have no broker, so this key is their production path.
    'google_address_validation' => [
        'api_key' => env('GOOGLE_ADDRESS_VALIDATION_API_KEY'),
        'base_url' => 'https://addressvalidation.googleapis.com',
    ],

    'azure' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'tenant' => env('AZURE_TENANT_ID', 'common'),
        'redirect' => '/auth/azure/callback',
        'proxy' => null,
    ],

    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
    ],

];
