<?php

use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyFulfillmentOrderActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Saloon\Http\Faking\MockResponse;
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
    ->in('Feature', 'Unit', 'External', 'Browser');

pest()->browser()->timeout(15000);

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

function something(): void
{
    // ..
}

function rateRequestForClient(int $clientId): RateRequest
{
    return new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
        clientId: $clientId,
    );
}

/*
|--------------------------------------------------------------------------
| Global Setup
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function (): void {
    Client::factory()->default()->create();
    Location::factory()->default()->create();
    Setting::updateOrCreate(
        ['key' => 'setup_complete'],
        ['value' => '1', 'type' => 'boolean', 'group' => 'system'],
    );
})->in('Feature', 'Unit', 'External', 'Browser');

/*
|--------------------------------------------------------------------------
| Carrier / DataSource credential helpers
|--------------------------------------------------------------------------
|
| Credentials live on the CarrierAccount and DataSource models (no longer the
| settings table). These helpers create a fully-configured account/source with
| a global scope so resolveForShipment() finds it, mirroring what the legacy
| global settings seeding used to provide. Call them explicitly in tests that
| need a configured carrier or import source.
|
*/

/**
 * @param  array<string, mixed>  $secrets
 * @param  array<string, mixed>  $credentials
 */
function createUspsAccount(array $secrets = [], array $credentials = []): CarrierAccount
{
    return makeCarrierAccount('USPS', array_merge([
        'client_id' => 'test_client_id',
        'client_secret' => 'test_client_secret',
    ], $secrets), array_merge([
        'crid' => 'test_crid',
        'mid' => 'test_mid',
    ], $credentials));
}

/**
 * @param  array<string, mixed>  $secrets
 * @param  array<string, mixed>  $credentials
 */
function createFedexAccount(array $secrets = [], array $credentials = []): CarrierAccount
{
    return makeCarrierAccount('FedEx', array_merge([
        'api_key' => 'test_api_key',
        'api_secret' => 'test_api_secret',
    ], $secrets), array_merge([
        'account_number' => 'test_account',
    ], $credentials));
}

/**
 * @param  array<string, mixed>  $secrets
 * @param  array<string, mixed>  $credentials
 */
function createUpsAccount(array $secrets = [], array $credentials = []): CarrierAccount
{
    return makeCarrierAccount('UPS', array_merge([
        'client_id' => 'test_client_id',
        'client_secret' => 'test_client_secret',
    ], $secrets), array_merge([
        // UPS account numbers are exactly six alphanumeric characters
        // (Shipping.yaml pins ShipperNumber to minLength/maxLength 6), so a
        // longer placeholder builds label requests UPS would reject.
        'account_number' => 'A1B2C3',
    ], $credentials));
}

/**
 * @param  array<string, mixed>  $secrets
 * @param  array<string, mixed>  $credentials
 */
function makeCarrierAccount(string $carrierName, array $secrets, array $credentials): CarrierAccount
{
    $carrier = Carrier::firstOrCreate(['name' => $carrierName]);

    $account = CarrierAccount::create([
        'carrier_id' => $carrier->id,
        'name' => "{$carrierName} Test Account",
        'active' => true,
        'credentials' => $credentials,
        'secret_credentials' => $secrets,
    ]);

    CarrierAccountScope::create([
        'carrier_account_id' => $account->id,
        'location_id' => null,
        'client_id' => null,
        'rate_shop' => false,
    ]);

    return $account;
}

/**
 * @param  array<string, mixed>  $settings  Non-secret settings (e.g. shop_domain, channel_name)
 * @param  array<string, mixed>  $secrets  Encrypted settings (e.g. client_id, client_secret, access_token)
 */
function createShopifyDataSource(array $settings = [], array $secrets = []): DataSource
{
    return DataSource::create([
        'name' => 'Shopify Test',
        'source_type' => ShopifySource::class,
        'active' => true,
        'settings' => array_merge([
            'channel_name' => 'Shopify',
            'shop_domain' => 'test-shop.myshopify.com',
        ], $settings),
        'secret_settings' => array_merge([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ], $secrets),
    ]);
}

/**
 * Put a client's consent to blind purchase on file — ADR-0003 decision 5, and
 * the gate every Shopify offer is advertised behind. Defaults to the default
 * client, which is the one every factory-made shipment belongs to.
 */
function allowBlindPurchase(?Package $package = null): void
{
    $client = $package?->shipment?->client;
    $client ??= Client::where('is_default', true)->first();

    $client?->update(['blind_purchase_enabled' => true]);
}

/**
 * A package shipped on a Shopify Shipping label: carrier of record USPS, postage
 * provenance pointing at the Shopify data source that bought it.
 */
function shippedShopifyPackage(array $attributes = []): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shipment = Shipment::factory()->create([
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);

    return Package::factory()->create(array_merge([
        'shipment_id' => $shipment->id,
        'carrier' => 'USPS',
        'service' => 'Ground Advantage',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => $source->id,
        'tracking_number' => '9400111899223197428490',
        'status' => PackageStatus::Shipped,
        'shipped_at' => now(),
        'label_data' => base64_encode('LABEL-BYTES'),
        'metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1'],
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $fulfillment  extra fields on the fulfillment node
 * @return array<string, mixed>
 */
function fulfillmentState(
    string $displayStatus,
    string $trackingNumber = '9400111899223197428490',
    array $fulfillment = [],
): array {
    return [
        'data' => [
            'fulfillmentOrder' => [
                'id' => 'gid://shopify/FulfillmentOrder/12345',
                'status' => 'CLOSED',
                'fulfillments' => [
                    'nodes' => [array_merge([
                        'id' => 'gid://shopify/Fulfillment/1',
                        'status' => 'SUCCESS',
                        'displayStatus' => $displayStatus,
                        'trackingInfo' => [['number' => $trackingNumber]],
                    ], $fulfillment)],
                ],
            ],
        ],
    ];
}

/**
 * Shopify's reply to the access-scopes query.
 *
 * Anything that verifies scopes against the live token — location
 * synchronization, fulfillment-order activation — issues this request before
 * its real work, so a faked sequence has to answer it first.
 *
 * @param  array<int, string>|null  $scopes  Defaults to every required scope.
 */
function shopifyAccessScopesResponse(?array $scopes = null): MockResponse
{
    $scopes ??= ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES;

    return MockResponse::make([
        'data' => ['currentAppInstallation' => [
            'accessScopes' => array_map(fn (string $handle): array => ['handle' => $handle], $scopes),
        ]],
    ]);
}

/*
|--------------------------------------------------------------------------
| Carrier / marketplace request schema validation
|--------------------------------------------------------------------------
|
| Asserting a request body against a hand-written golden array only proves the
| body matches what we thought we were building. If we built the wrong shape and
| froze that same wrong shape into the test, both agree and the live API 400s.
| These helpers check the body against the carrier's published spec instead.
|
| Schemas are vendored under tests/Fixtures/Schemas — see the README there for
| provenance, licensing, and how to refresh them.
|
*/

/**
 * Validate a request body against a named schema in a vendored API document.
 *
 * Handles both Swagger 2.0 documents, which hold schemas under "definitions"
 * (Amazon SP-API), and OpenAPI 3 documents, which hold them under
 * "components/schemas" (UPS). The caller passes the schema name; which pointer
 * to build is worked out from the document.
 *
 * @param  array<string, mixed>  $body
 * @param  string  $schema  A schema name, e.g. "ConfirmShipmentRequest" or "RATERequestWrapper"
 * @param  string  $document  Basename of a file in tests/Fixtures/Schemas
 *
 * @throws AssertionFailedError
 */
function assertMatchesApiSchema(array $body, string $schema, string $document): void
{
    $path = __DIR__.'/Fixtures/Schemas/'.$document.'.json';

    if (! is_file($path)) {
        Assert::fail("Vendored schema [{$document}.json] is missing from tests/Fixtures/Schemas.");
    }

    $document_ = json_decode((string) file_get_contents($path), true);

    if (isset($document_['definitions'][$schema])) {
        $pointer = '#/definitions/'.$schema;
    } elseif (isset($document_['components']['schemas'][$schema])) {
        $pointer = '#/components/schemas/'.$schema;
    } else {
        Assert::fail("Schema [{$schema}] is not defined in {$document}.json.");
    }

    $validator = new Validator;

    // The validator wants stdClass for "type": "object"; a PHP associative
    // array decodes as an array and fails every object constraint. It also
    // takes the value by reference, so it has to be a variable.
    $decoded = json_decode(json_encode($body));

    $validator->validate($decoded, (object) ['$ref' => 'file://'.$path.$pointer]);

    if (! $validator->isValid()) {
        $failures = array_map(
            fn (array $error): string => '  - '.($error['property'] !== '' ? $error['property'].': ' : '').$error['message'],
            $validator->getErrors(),
        );

        Assert::fail(
            "Request body does not conform to {$document}{$pointer}:\n"
            .implode("\n", $failures)
            ."\n\nBody sent:\n".json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // Record the pass, so a test whose only check is this helper isn't reported
    // as risky for performing no assertions.
    Assert::assertTrue($validator->isValid());
}

/**
 * Validate a body against a vendored Amazon SP-API schema.
 *
 * Defaults to the Orders document, which was the only one for a while. Amazon
 * Buy Shipping passes `shippingV2`.
 *
 * @param  array<string, mixed>  $body
 */
function assertMatchesSpApiSchema(array $body, string $schema, string $document = 'ordersV0'): void
{
    assertMatchesApiSchema($body, $schema, $document);
}

/**
 * Validate a body against a UPS API schema.
 *
 * @param  array<string, mixed>  $body
 */
function assertMatchesUpsSchema(array $body, string $schema, string $document): void
{
    assertMatchesApiSchema($body, $schema, $document);
}

/**
 * Validate a body against our hand-written USPS label schema.
 *
 * USPS's own spec cannot be vendored, so tests/Fixtures/Schemas/uspsLabel.json
 * describes the bodies UspsAdapter builds — "LabelRequest" for the domestic
 * label API and "InternationalLabelRequest" for the international one.
 *
 * @param  array<string, mixed>  $body
 */
function assertMatchesUspsSchema(array $body, string $schema): void
{
    assertMatchesApiSchema($body, $schema, 'uspsLabel');
}
