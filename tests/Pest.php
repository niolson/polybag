<?php

use App\Models\Location;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Global Setup
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function (): void {
    Location::factory()->default()->create();
    Setting::updateOrCreate(
        ['key' => 'setup_complete'],
        ['value' => '1', 'type' => 'boolean', 'group' => 'system'],
    );
    Setting::updateOrCreate(
        ['key' => 'usps.client_id'],
        ['value' => 'test_client_id', 'type' => 'string', 'group' => 'usps'],
    );
    Setting::updateOrCreate(
        ['key' => 'usps.client_secret'],
        ['value' => 'test_client_secret', 'type' => 'string', 'group' => 'usps'],
    );
    Setting::updateOrCreate(
        ['key' => 'usps.crid'],
        ['value' => 'test_crid', 'type' => 'string', 'group' => 'usps'],
    );
    Setting::updateOrCreate(
        ['key' => 'usps.mid'],
        ['value' => 'test_mid', 'type' => 'string', 'group' => 'usps'],
    );
    Setting::updateOrCreate(
        ['key' => 'fedex.api_key'],
        ['value' => 'test_api_key', 'type' => 'string', 'group' => 'fedex'],
    );
    Setting::updateOrCreate(
        ['key' => 'fedex.api_secret'],
        ['value' => 'test_api_secret', 'type' => 'string', 'group' => 'fedex'],
    );
    Setting::updateOrCreate(
        ['key' => 'fedex.account_number'],
        ['value' => 'test_account', 'type' => 'string', 'group' => 'fedex'],
    );
    Setting::updateOrCreate(
        ['key' => 'ups.client_id'],
        ['value' => 'test_client_id', 'type' => 'string', 'group' => 'ups'],
    );
    Setting::updateOrCreate(
        ['key' => 'ups.client_secret'],
        ['value' => 'test_client_secret', 'type' => 'string', 'group' => 'ups'],
    );
    Setting::updateOrCreate(
        ['key' => 'ups.account_number'],
        ['value' => 'test_account', 'type' => 'string', 'group' => 'ups'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.shop_domain'],
        ['value' => 'test-shop.myshopify.com', 'type' => 'string', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.client_id'],
        ['value' => 'test-client-id', 'type' => 'string', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.client_secret'],
        ['value' => 'test-client-secret', 'type' => 'string', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.api_version'],
        ['value' => '2025-01', 'type' => 'string', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'amazon.client_id'],
        ['value' => 'test-client-id', 'type' => 'string', 'group' => 'amazon'],
    );
    Setting::updateOrCreate(
        ['key' => 'amazon.client_secret'],
        ['value' => 'test-client-secret', 'type' => 'string', 'group' => 'amazon'],
    );
    Setting::updateOrCreate(
        ['key' => 'amazon.refresh_token'],
        ['value' => 'test-refresh-token', 'type' => 'string', 'group' => 'amazon'],
    );
    Setting::updateOrCreate(
        ['key' => 'amazon.marketplace_id'],
        ['value' => 'ATVPDKIKX0DER', 'type' => 'string', 'group' => 'amazon'],
    );
})->in('Feature', 'Unit');
