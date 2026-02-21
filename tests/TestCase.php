<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set dummy carrier credentials so Saloon OAuth connectors
        // don't throw TypeError on null values in CI environments.
        config([
            'services.fedex.api_key' => 'test_api_key',
            'services.fedex.api_secret' => 'test_api_secret',
            'services.fedex.account_number' => 'test_account',
            'services.usps.client_id' => 'test_client_id',
            'services.usps.client_secret' => 'test_client_secret',
            'services.usps.crid' => 'test_crid',
            'services.usps.mid' => 'test_mid',
            'services.ups.client_id' => 'test_client_id',
            'services.ups.client_secret' => 'test_client_secret',
        ]);
    }
}
