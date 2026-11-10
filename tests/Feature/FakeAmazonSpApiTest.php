<?php

use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\BuyShippingBenefits;
use App\DataTransferObjects\Shipping\RateRequest;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\CancelAmazonShipment;
use App\Http\Integrations\Amazon\Requests\GetShipmentDocuments;
use App\Http\Integrations\Amazon\Requests\GetShipmentTracking;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Http\Integrations\Amazon\Requests\PurchaseShipment;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\FakeAmazonSpApi;
use App\Services\PackageShipping\EloquentPackageShippingWorkflow;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A Prime order with one Transparency item and one plain item, shaped as
 * polybag-demo-data-tools writes it.
 *
 * @return array<string, mixed>
 */
function fixtureAmazonOrder(string $orderId = '113-0000001-0000001'): array
{
    $item = fn (string $id, string $sku, array $programs): array => [
        'orderItemId' => $id,
        'quantityOrdered' => 1,
        'programs' => $programs,
        'product' => ['sellerSku' => $sku, 'title' => "Product {$sku}"],
        'fulfillment' => ['quantityFulfilled' => 0],
        'proceeds' => ['breakdowns' => [['type' => 'ITEM', 'subtotal' => ['amount' => '19.99', 'currencyCode' => 'USD']]]],
    ];

    return [
        'orderId' => $orderId,
        'createdTime' => now()->subHours(3)->toIso8601ZuluString(),
        'lastUpdatedTime' => now()->subHours(3)->toIso8601ZuluString(),
        'programs' => ['PRIME'],
        'salesChannel' => ['channelName' => 'AMAZON', 'marketplaceId' => 'ATVPDKIKX0DER'],
        'fulfillment' => [
            'fulfillmentStatus' => 'UNSHIPPED',
            'fulfilledBy' => 'MERCHANT',
            'fulfillmentServiceLevel' => 'STANDARD',
            'shipByWindow' => ['latestDateTime' => now()->addDay()->toIso8601ZuluString()],
            'deliverByWindow' => ['latestDateTime' => now()->addWeekdays(4)->toIso8601ZuluString()],
        ],
        'recipient' => ['deliveryAddress' => [
            'name' => 'Pat Example',
            'addressLine1' => '456 Oak Ave',
            'city' => 'Seattle',
            'stateOrRegion' => 'WA',
            'postalCode' => '98101',
            'countryCode' => 'US',
        ]],
        'buyer' => ['buyerEmail' => 'pat@marketplace.amazon.com'],
        'orderItems' => [
            $item('ITEM-1', 'FIXTURE-1', ['TRANSPARENCY']),
            $item('ITEM-2', 'FIXTURE-2', []),
        ],
    ];
}

function loadFixtureOrders(array $orders): DataSource
{
    $path = tempnam(sys_get_temp_dir(), 'amazon-fixture-');
    file_put_contents($path, json_encode($orders));

    $exitCode = Artisan::call('demo:amazon-fixture', ['orders' => $path, '--import' => true]);
    unlink($path);

    expect($exitCode)->toBe(0, Artisan::output());

    return DataSource::query()->where('name', 'Amazon (demo fixture)')->sole();
}

/** A packed parcel on an imported fixture order. */
function fixturePackage(): Package
{
    $shipment = Shipment::query()->where('shipment_reference', '113-0000001-0000001')->sole();
    $shipment->update(['shipping_method_id' => ShippingMethod::factory()->create()->id]);
    $package = Package::factory()->create(['shipment_id' => $shipment->id, 'weight' => 0.75, 'length' => 9.0, 'width' => 6.0, 'height' => 2.0]);

    $shipment->shipmentItems->each(fn (ShipmentItem $item) => PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $item->id,
        'product_id' => $item->product_id,
        'quantity' => $item->quantity,
    ]));

    return $package->fresh();
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['app.fake_carriers' => true]);
    // SP-API imports run only with MFA required, fixture or not.
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
});

it('writes fixture orders in the published Orders v2026 shape', function (): void {
    assertMatchesSpApiSchema(fixtureAmazonOrder(), 'Order', 'orders_2026-01-01');
});

it('imports a fixture connection\'s orders with their Prime and Transparency programs', function (): void {
    $source = loadFixtureOrders([fixtureAmazonOrder()]);

    $shipment = Shipment::query()->where('shipment_reference', '113-0000001-0000001')->sole();
    $items = $shipment->shipmentItems->keyBy('source_item_id');

    expect($source->sells_postage)->toBeTrue()
        ->and($shipment->data_source_id)->toBe($source->id)
        ->and($shipment->metadata['amazon_programs'])->toBe(['PRIME'])
        ->and($items['ITEM-1']->transparency)->toBeTrue()
        ->and($items['ITEM-2']->transparency)->toBeFalse();

    Http::assertNothingSent();
});

it('reuses its one fixture connection and replaces the orders on a second load', function (): void {
    loadFixtureOrders([fixtureAmazonOrder()]);
    $source = loadFixtureOrders([fixtureAmazonOrder(), fixtureAmazonOrder('113-0000002-0000002')]);

    expect(DataSource::query()->where('source_type', AmazonSource::class)->count())->toBe(1)
        ->and((new FakeAmazonSpApi)->orders($source->id))->toHaveCount(2);
});

it('quotes three Buy Shipping offers, the cheapest not OTDR-protected for late-delivery risk', function (): void {
    loadFixtureOrders([fixtureAmazonOrder()]);

    $rates = (new AmazonBuyShippingAdapter)->getRates(RateRequest::fromPackage(fixturePackage()), []);
    $benefits = $rates->mapWithKeys(fn ($rate): array => [$rate->serviceName => BuyShippingBenefits::fromRateMetadata($rate->metadata)]);

    expect($rates)->toHaveCount(3)
        ->and($benefits['Amazon Shipping Ground']->isOtdrProtected())->toBeTrue()
        ->and($benefits['UPS® Ground']->isOtdrProtected())->toBeTrue()
        ->and($benefits['USPS Ground Advantage (less than 1lb)']->isOtdrProtected())->toBeFalse()
        ->and($benefits['USPS Ground Advantage (less than 1lb)']->otdrExclusionReasons())->not->toBeEmpty()
        ->and($rates->sortBy('price')->first()->serviceName)->toBe('USPS Ground Advantage (less than 1lb)');
});

it('buys a sample label through the shipping workflow', function (): void {
    $source = loadFixtureOrders([fixtureAmazonOrder()]);
    $package = fixturePackage();
    $rate = (new AmazonBuyShippingAdapter)->getRates(RateRequest::fromPackage($package), [])
        ->firstWhere('serviceName', 'UPS® Ground');

    $result = app(EloquentPackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'pdf',
    ));

    $label = $package->fresh()->activeLabel()->firstOrFail();

    expect($result->success)->toBeTrue()
        ->and($label->postage_data_source_id)->toBe($source->id)
        ->and($label->tracking_number)->toStartWith('1Z999')
        ->and(base64_decode((string) $package->fresh()->label_data))->toStartWith('%PDF-')
        ->and(base64_decode((string) $package->fresh()->label_data))->toContain('SAMPLE');

    Http::assertNothingSent();
});

it('answers every Buy Shipping call in the published Shipping v2 shape', function (): void {
    $source = loadFixtureOrders([fixtureAmazonOrder()]);
    $connector = AmazonSpApiConnector::fromSettings([...$source->settings, '_data_source_id' => $source->id]);

    $rates = $connector->send(new GetShippingRates(['shipTo' => []]))->json();
    $purchase = $connector->send(new PurchaseShipment([
        'requestToken' => $rates['payload']['requestToken'],
        'rateId' => $rates['payload']['rates'][1]['rateId'],
        'requestedDocumentSpecification' => ['format' => 'ZPL', 'dpi' => 203],
    ]))->json();

    assertMatchesApiSchema($rates, 'GetRatesResponse', 'shippingV2');
    assertMatchesApiSchema($purchase, 'PurchaseShipmentResponse', 'shippingV2');
    assertMatchesApiSchema($connector->send(new GetShipmentDocuments('amzn1.sid.fixture', '1', 'PDF'))->json(), 'GetShipmentDocumentsResponse', 'shippingV2');
    assertMatchesApiSchema($connector->send(new GetShipmentTracking('1Z999', 'UPS'))->json(), 'GetTrackingResponse', 'shippingV2');
    // Amazon answers a cancel with `"payload": []`, as captured, though its
    // schema says object; the fake answers as Amazon does.
    expect($connector->send(new CancelAmazonShipment('amzn1.sid.fixture'))->successful())->toBeTrue();

    expect(base64_decode($purchase['payload']['packageDocumentDetails'][0]['packageDocuments'][0]['contents']))->toStartWith('^XA');
});

it('never sends a fixture connection anywhere while fake carriers are off', function (): void {
    $source = loadFixtureOrders([fixtureAmazonOrder()]);
    config(['app.fake_carriers' => false]);

    expect(fn (): AmazonSpApiConnector => AmazonSpApiConnector::fromSettings([...$source->settings, '_data_source_id' => $source->id]))
        ->toThrow(RuntimeException::class, 'FAKE_CARRIERS');

    Http::assertNothingSent();
});

it('leaves a real Amazon connection to Amazon', function (): void {
    $connector = AmazonSpApiConnector::fromSettings(['refresh_token' => 'real', 'marketplace_ids' => ['ATVPDKIKX0DER']]);

    expect($connector->hasMockClient())->toBeFalse();
});

it('refuses to load orders while fake carriers are off', function (): void {
    config(['app.fake_carriers' => false]);
    $path = tempnam(sys_get_temp_dir(), 'amazon-fixture-');
    file_put_contents($path, json_encode([fixtureAmazonOrder()]));

    $this->artisan('demo:amazon-fixture', ['orders' => $path])->assertFailed();

    expect(DataSource::query()->where('source_type', AmazonSource::class)->exists())->toBeFalse();
    unlink($path);
});

it('refuses a file that is not a list of orders', function (string $contents): void {
    $path = tempnam(sys_get_temp_dir(), 'amazon-fixture-');
    file_put_contents($path, $contents);

    $this->artisan('demo:amazon-fixture', ['orders' => $path])->assertFailed();

    expect(DataSource::query()->where('source_type', AmazonSource::class)->exists())->toBeFalse();
    unlink($path);
})->with([
    'not JSON' => ['{orders'],
    'an object' => ['{"orderId": "1"}'],
    'an order without an id' => ['[{"programs": []}]'],
]);
