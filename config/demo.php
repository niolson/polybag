<?php

/*
|--------------------------------------------------------------------------
| Sales demo tenants
|--------------------------------------------------------------------------
|
| Read only when APP_ENV is `demo` (`live-store-demo/04`). Our hosting
| tooling writes these into a demo tenant's environment; nothing here is a
| self-host setting.
|
*/

return [

    /*
    | When the demo ends, as anything Carbon parses, in UTC unless it names a
    | zone. Past it, nothing imports and nobody can sign in, until the tenant
    | is torn down. Unset means no limit. A value that cannot be read counts
    | as already ended, so a typo locks the demo rather than leaving it open.
    */
    'expires_at' => env('DEMO_EXPIRES_AT'),

    /*
    | Sandbox carrier accounts `demo:provision` creates, so no one types them
    | into each demo. A carrier with any value missing is skipped.
    */
    'carriers' => [
        'usps' => [
            'client_id' => env('DEMO_USPS_CLIENT_ID'),
            'client_secret' => env('DEMO_USPS_CLIENT_SECRET'),
            'crid' => env('DEMO_USPS_CRID'),
            'mid' => env('DEMO_USPS_MID'),
            // Optional: labels bill the CRID when it is blank.
            'eps_account' => env('DEMO_USPS_EPS_ACCOUNT'),
        ],
        'ups' => [
            'client_id' => env('DEMO_UPS_CLIENT_ID'),
            'client_secret' => env('DEMO_UPS_CLIENT_SECRET'),
            'account_number' => env('DEMO_UPS_ACCOUNT_NUMBER'),
        ],
        'fedex' => [
            'api_key' => env('DEMO_FEDEX_API_KEY'),
            'api_secret' => env('DEMO_FEDEX_API_SECRET'),
            'account_number' => env('DEMO_FEDEX_ACCOUNT_NUMBER'),
        ],
    ],

    /*
    | Our sandbox SP-API app, for showing Amazon Shipping on orders from
    | other channels to a prospect with no Amazon seller account. These
    | credentials only work on the sandbox host, which is where every
    | postage request goes in Demo.
    */
    'amazon_shipping' => [
        'client_id' => env('DEMO_AMAZON_CLIENT_ID'),
        'client_secret' => env('DEMO_AMAZON_CLIENT_SECRET'),
        'refresh_token' => env('DEMO_AMAZON_REFRESH_TOKEN'),
        'marketplace_id' => env('DEMO_AMAZON_MARKETPLACE_ID', 'ATVPDKIKX0DER'),
    ],

];
