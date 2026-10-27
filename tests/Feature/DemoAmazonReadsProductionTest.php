<?php

use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\Enums\Deliverability;
use App\Enums\PackageStatus;
use App\Enums\SourceEnvironment;
use App\Filament\Pages\Ship;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\AmazonSpApiEnvironment;
use App\Http\Integrations\Amazon\PostageRequest;
use App\Http\Integrations\Amazon\ReadsSellerData;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\GetMarketplaceParticipations;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Http\Integrations\Amazon\Requests\PurchaseShipment;
use App\Http\Integrations\Amazon\Requests\SearchCatalogItems;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Models\CarrierAccountScope;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\AmazonMarketplaceDiscoveryService;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\AmazonShippingAdapter;
use App\Services\PackageShipping\EloquentPackageShippingWorkflow;
use App\Services\SettingsService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\Validation\ShipmentValidationPlan;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

/**
 * `live-store-demo/03`: in Demo, Amazon reads the seller's orders, catalog and
 * marketplaces from production and buys postage on the sandbox. The split is
 * declared on each request class, and the import keys its sandbox branches on
 * the same answer the connector sends by. An imported order keeps only where
 * it is going.
 */
const DEMO_AMAZON_PRODUCTION_HOST = 'https://sellingpartnerapi-na.amazon.com';

const DEMO_AMAZON_SANDBOX_HOST = 'https://sandbox.sellingpartnerapi-na.amazon.com';

beforeEach(function (): void {
    Setting::updateOrCreate(['key' => 'require_mfa'], ['value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    Cache::put('amazon_sp_api_access_token_'.md5('demo-refresh-token'), 'demo-access-token', 3600);

    $this->amazon = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'sells_postage' => true,
        'name' => 'Prospect Amazon',
        'settings' => ['channel_name' => 'Amazon', 'marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => [
            'client_id' => 'demo-client-id',
            'client_secret' => 'demo-client-secret',
            'refresh_token' => 'demo-refresh-token',
        ],
    ]);
});

/**
 * Every SP-API request class the app has, keyed by short name.
 *
 * @return array<string, class-string<Request>>
 */
function demoAmazonRequestClasses(): array
{
    $classes = [];

    foreach (glob(app_path('Http/Integrations/Amazon/Requests/*.php')) as $file) {
        $name = basename($file, '.php');
        $classes[$name] = 'App\\Http\\Integrations\\Amazon\\Requests\\'.$name;
    }

    ksort($classes);

    return $classes;
}

/**
 * An instance of a request class, its required arguments filled with stand-ins.
 *
 * @param  class-string<Request>  $class
 */
function demoAmazonRequest(string $class): Request
{
    $arguments = [];

    foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        if ($parameter->isOptional()) {
            break;
        }

        $arguments[] = $parameter->getType() instanceof ReflectionNamedType && $parameter->getType()->getName() === 'array' ? [] : 'TEST';
    }

    return new $class(...$arguments);
}

function demoAmazonConnector(): AmazonSpApiConnector
{
    return AmazonSpApiConnector::fromSettings([
        'client_id' => 'demo-client-id',
        'client_secret' => 'demo-client-secret',
        'refresh_token' => 'demo-refresh-token',
        'auth_mode' => 'manual',
    ]);
}

function demoAmazonHostOf(Request $request): string
{
    $url = demoAmazonConnector()->createPendingRequest($request)->getUrl();

    return (string) preg_replace('#^(https://[^/]+).*$#', '$1', $url);
}

/**
 * An order as Amazon's production search returns it with RECIPIENT, carrying
 * every kind of buyer data a demo must not keep.
 *
 * @return array<string, mixed>
 */
function demoAmazonOrder(string $orderId = '111-0000000-0000001'): array
{
    return [
        'orderId' => $orderId,
        'fulfillment' => [
            'fulfillmentStatus' => 'PARTIALLY_SHIPPED',
            'fulfilledBy' => 'MERCHANT',
            'fulfillmentServiceLevel' => 'STANDARD',
        ],
        'buyer' => [
            'buyerName' => 'Prospect Buyer',
            'buyerEmail' => 'buyer-alias@marketplace.amazon.com',
            'buyerCompanyName' => 'Buyer Holdings',
        ],
        'recipient' => [
            'deliveryAddress' => [
                'name' => 'Rhonda Recipient',
                'companyName' => 'Recipient Co',
                'addressLine1' => '4821 Private Lane',
                'addressLine2' => 'Unit 9',
                'addressLine3' => 'Rear door',
                'city' => 'Lemont',
                'districtOrCounty' => 'Cook',
                'stateOrRegion' => 'IL',
                'postalCode' => '60439',
                'countryCode' => 'US',
                'phone' => '+1 630-555-0142',
                'addressType' => 'COMMERCIAL',
            ],
            'deliveryPreference' => ['addressInstruction' => 'Leave with Rhonda next door'],
        ],
        'orderItems' => [[
            'orderItemId' => 'DEMO-ITEM-1',
            'product' => [
                'asin' => 'B000DEMO01',
                'sellerSku' => 'DEMO-SKU-1',
                'title' => 'Demo Widget',
                'customization' => ['customizedUrl' => 'https://example.test/custom/rhonda'],
            ],
            'quantityOrdered' => 3,
            'fulfillment' => [
                'quantityFulfilled' => 1,
                'packing' => ['giftOption' => ['giftMessage' => 'Happy birthday Rhonda']],
            ],
            'proceeds' => ['breakdowns' => [[
                'type' => 'ITEM',
                'subtotal' => ['amount' => '30.00', 'currencyCode' => 'USD'],
            ]]],
        ]],
    ];
}

/**
 * Buyer data from {@see demoAmazonOrder()} that must appear nowhere.
 *
 * @return list<string>
 */
function demoAmazonBuyerData(): array
{
    return [
        'Prospect Buyer', 'buyer-alias@', 'Buyer Holdings', 'Rhonda', 'Recipient Co', '4821 Private',
        'Unit 9', 'Rear door', 'Cook', '630-555-0142', '6305550142', 'COMMERCIAL', 'Happy birthday',
        'example.test/custom',
    ];
}

function demoAmazonImport(DataSource $source): void
{
    ShipmentImportService::forRecord($source)->import();
}

/**
 * A withheld Amazon Shipment as a Demo import leaves it, packed and ready to
 * buy for.
 */
function demoWithheldPackage(?DataSource $source = null): Package
{
    $shipment = Shipment::factory()->create([
        'data_source_id' => $source?->id,
        'shipment_reference' => '111-0000000-0000001',
        'first_name' => null,
        'last_name' => null,
        'company' => null,
        'address1' => Shipment::WITHHELD_ADDRESS_LINE,
        'address2' => null,
        'city' => 'Lemont',
        'state_or_province' => 'IL',
        'postal_code' => '60439',
        'country' => 'US',
        'phone' => null,
        'phone_e164' => null,
        'email' => null,
        'residential' => null,
        'validated_residential' => null,
        'metadata' => ['amazon_order_id' => '111-0000000-0000001'],
        ...Shipment::UNVALIDATED,
    ]);

    $package = Package::factory()->create([
        'shipment_id' => $shipment->id,
        'weight' => 0.75,
        'length' => 9.0,
        'width' => 6.0,
        'height' => 2.0,
    ]);

    $item = $shipment->shipmentItems()->create([
        'product_id' => Product::factory()->create(['weight' => 0.5])->id,
        'source_item_id' => 'DEMO-ITEM-1',
        'quantity' => 1,
        'value' => 10.0,
    ]);
    PackageItem::factory()->create([
        'package_id' => $package->id,
        'shipment_item_id' => $item->id,
        'product_id' => $item->product_id,
        'quantity' => 1,
    ]);

    return $package->fresh();
}

function demoAmazonRatesResponse(): MockResponse
{
    return MockResponse::make(['payload' => [
        'requestToken' => 'amzn1.rq.demo-sandbox-token',
        'rates' => [[
            'rateId' => 'demo-sandbox-rate',
            'carrierId' => 'AMZN_US',
            'carrierName' => 'Amazon Shipping',
            'serviceId' => 'std-us-swa-mfn',
            'serviceName' => 'Amazon Shipping Ground',
            'totalCharge' => ['unit' => 'USD', 'value' => 4.76],
            'promise' => ['deliveryWindow' => ['start' => '2026-10-25T06:59:59Z', 'end' => '2026-10-27T06:59:59Z']],
            'supportedDocumentSpecifications' => [[
                'format' => 'ZPL',
                'size' => ['width' => 4, 'length' => 6, 'unit' => 'INCH'],
                'printOptions' => [['supportedDPIs' => [203, 300], 'supportedPageLayouts' => ['DEFAULT'], 'supportedFileJoiningOptions' => [true], 'supportedDocumentDetails' => [['name' => 'LABEL', 'isMandatory' => true]]]],
            ]],
        ]],
        'ineligibleRates' => [],
    ]]);
}

/*
|--------------------------------------------------------------------------
| The split, by request class
|--------------------------------------------------------------------------
*/

describe('host selection', function (): void {
    // These build requests to read their URLs and send nothing.
    beforeEach(fn () => TestCase::allowStraySaloonRequests());

    it('declares every request a read, postage, or, for the order write, neither', function (): void {
        $kinds = collect(demoAmazonRequestClasses())->map(fn (string $class): string => match (true) {
            is_a($class, ReadsSellerData::class, true) && is_a($class, PostageRequest::class, true) => 'both',
            is_a($class, ReadsSellerData::class, true) => 'read',
            is_a($class, PostageRequest::class, true) => 'postage',
            default => 'neither',
        })->all();

        expect($kinds)->toBe([
            'CancelAmazonShipment' => 'postage',
            'ConfirmShipment' => 'neither',
            'GetAdditionalInputsSchema' => 'postage',
            'GetMarketplaceParticipations' => 'read',
            'GetShipmentDocuments' => 'postage',
            'GetShipmentTracking' => 'postage',
            'GetShippingRates' => 'postage',
            'PurchaseShipment' => 'postage',
            'SearchCatalogItems' => 'read',
            'SearchOrders' => 'read',
        ]);
    });

    it('sends the order search, catalog and marketplace discovery to production in demo', function (): void {
        $this->app['env'] = 'demo';

        foreach ([SearchOrders::class, SearchCatalogItems::class, GetMarketplaceParticipations::class] as $class) {
            expect(demoAmazonHostOf(demoAmazonRequest($class)))->toBe(DEMO_AMAZON_PRODUCTION_HOST, $class);
        }

        expect(demoAmazonConnector()->createPendingRequest(new SearchOrders(['marketplaceIds' => 'ATVPDKIKX0DER']))->getUrl())
            ->toBe(DEMO_AMAZON_PRODUCTION_HOST.'/orders/2026-01-01/orders');
    });

    it('never sends a postage request to the production host in demo', function (): void {
        $this->app['env'] = 'demo';

        $postage = collect(demoAmazonRequestClasses())
            ->filter(fn (string $class): bool => is_a($class, PostageRequest::class, true));

        expect($postage)->toHaveCount(6);

        foreach ($postage as $class) {
            $host = demoAmazonHostOf(demoAmazonRequest($class));

            expect($host)->toStartWith('https://sandbox.', $class)
                ->and($host)->not->toBe(DEMO_AMAZON_PRODUCTION_HOST, $class);
        }
    });

    it('keeps a request that declares no kind on the sandbox in demo', function (): void {
        $this->app['env'] = 'demo';

        $unmarked = new class extends Request
        {
            protected Method $method = Method::GET;

            public function resolveEndpoint(): string
            {
                return '/orders/v0/orders/TEST/address';
            }
        };

        expect(demoAmazonHostOf($unmarked))->toBe(DEMO_AMAZON_SANDBOX_HOST)
            ->and(demoAmazonHostOf(new ConfirmShipment('TEST')))->toBe(DEMO_AMAZON_SANDBOX_HOST);
    });

    it('sends every request to production in production', function (): void {
        $this->app['env'] = 'production';

        foreach (demoAmazonRequestClasses() as $class) {
            expect(demoAmazonHostOf(demoAmazonRequest($class)))->toBe(DEMO_AMAZON_PRODUCTION_HOST, $class);
        }
    });

    it('sends every request where sandbox_mode says in dev', function (bool $sandbox): void {
        app(SettingsService::class)->set('sandbox_mode', $sandbox);

        foreach (demoAmazonRequestClasses() as $class) {
            $host = demoAmazonHostOf(demoAmazonRequest($class));

            expect(str_starts_with($host, 'https://sandbox.'))->toBe($sandbox, $class);
        }

        // The order search keeps its Far East sandbox region outside Demo.
        expect(demoAmazonHostOf(new SearchOrders))->toBe($sandbox ? 'https://sandbox.sellingpartnerapi-fe.amazon.com' : DEMO_AMAZON_PRODUCTION_HOST);
    })->with(['sandbox on' => true, 'sandbox off' => false]);

    it('tells the import where orders are read from, as the connector sends the search', function (string $environment, bool $sandbox, bool $readsFromSandbox): void {
        $this->app['env'] = $environment;
        app(SettingsService::class)->set('sandbox_mode', $sandbox);

        expect(app(AmazonSpApiEnvironment::class)->readsFromSandbox())->toBe($readsFromSandbox)
            ->and(str_starts_with(demoAmazonHostOf(new SearchOrders), 'https://sandbox.'))->toBe($readsFromSandbox);
    })->with([
        'demo' => ['demo', true, false],
        'production' => ['production', true, false],
        'dev, sandbox on' => ['testing', true, true],
        'dev, sandbox off' => ['testing', false, false],
    ]);
});

/*
|--------------------------------------------------------------------------
| The import in Demo
|--------------------------------------------------------------------------
*/

it('imports the seller\'s open orders from production in demo, as real orders', function (): void {
    $this->app['env'] = 'demo';

    Saloon::fake([
        SearchOrders::class => MockResponse::make(['orders' => [demoAmazonOrder()]]),
        SearchCatalogItems::class => MockResponse::make(['numberOfResults' => 1, 'items' => [[
            'asin' => 'B000DEMO01',
            'identifiers' => [['marketplaceId' => 'ATVPDKIKX0DER', 'identifiers' => [['identifierType' => 'UPC', 'identifier' => '012345678905']]]],
        ]]]),
    ]);

    demoAmazonImport($this->amazon);

    $shipment = Shipment::where('shipment_reference', '111-0000000-0000001')->firstOrFail();

    // The quantity left to ship, not the quantity ordered.
    expect($shipment->shipmentItems)->toHaveCount(1)
        ->and($shipment->shipmentItems->first()->quantity)->toBe(2)
        // The product auto-update ran against the production catalog.
        ->and($shipment->shipmentItems->first()->product?->barcode)->toBe('012345678905');

    // Not the JP test-case search, and no BUYER asked for.
    Saloon::assertSent(function (Request $request, Response $response): bool {
        if (! $request instanceof SearchOrders) {
            return false;
        }

        $query = $request->query()->all();

        return str_starts_with($response->getPendingRequest()->getUrl(), DEMO_AMAZON_PRODUCTION_HOST.'/')
            && $query['marketplaceIds'] === 'ATVPDKIKX0DER'
            && str_contains($query['includedData'], 'RECIPIENT')
            && ! str_contains($query['includedData'], 'BUYER');
    });
    Saloon::assertSent(fn (Request $request, Response $response): bool => $request instanceof SearchCatalogItems
        && str_starts_with($response->getPendingRequest()->getUrl(), DEMO_AMAZON_PRODUCTION_HOST.'/'));
});

it('discovers the seller\'s marketplaces from production in demo', function (): void {
    $this->app['env'] = 'demo';
    $connected = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'sells_postage' => true,
        'settings' => [],
        'secret_settings' => ['client_id' => 'demo-client-id', 'client_secret' => 'demo-client-secret', 'refresh_token' => 'demo-refresh-token'],
    ]);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make(['payload' => [[
            'marketplace' => ['id' => 'ATVPDKIKX0DER'],
            'participation' => ['isParticipating' => true, 'hasSuspendedListings' => false],
        ]]]),
    ]);

    $result = app(AmazonMarketplaceDiscoveryService::class)->discover($connected);

    expect($result->succeeded)->toBeTrue()
        ->and($result->selectedMarketplaceId)->toBe('ATVPDKIKX0DER');

    Saloon::assertSent(fn (Request $request, Response $response): bool => $request instanceof GetMarketplaceParticipations
        && str_starts_with($response->getPendingRequest()->getUrl(), DEMO_AMAZON_PRODUCTION_HOST.'/'));
});

/*
|--------------------------------------------------------------------------
| No buyer data in Demo
|--------------------------------------------------------------------------
*/

it('keeps only city, state, postal code and country of an Amazon order in demo', function (): void {
    $this->app['env'] = 'demo';
    Saloon::fake([
        SearchOrders::class => MockResponse::make(['orders' => [demoAmazonOrder()]]),
        SearchCatalogItems::class => MockResponse::make(['numberOfResults' => 0, 'items' => []]),
    ]);

    demoAmazonImport($this->amazon);

    $shipment = Shipment::where('shipment_reference', '111-0000000-0000001')->firstOrFail();

    expect($shipment->address1)->toBe(Shipment::WITHHELD_ADDRESS_LINE)
        ->and($shipment->isAddressWithheld())->toBeTrue()
        ->and($shipment->only(['city', 'state_or_province', 'postal_code', 'country']))->toBe([
            'city' => 'Lemont',
            'state_or_province' => 'IL',
            'postal_code' => '60439',
            'country' => 'US',
        ])
        ->and($shipment->only(['first_name', 'last_name', 'company', 'address2', 'phone', 'phone_e164', 'email', 'residential']))
        ->each->toBeNull();

    $stored = json_encode([
        $shipment->getAttributes(),
        $shipment->shipmentItems->map->getAttributes()->all(),
        $shipment->shipmentItems->map(fn ($item) => $item->product?->getAttributes())->all(),
    ]);

    foreach (demoAmazonBuyerData() as $buyerData) {
        expect($stored)->not->toContain($buyerData);
    }
});

it('logs no buyer data while importing in demo, even from a row that fails', function (): void {
    $this->app['env'] = 'demo';

    $failing = demoAmazonOrder('111-0000000-0000002');
    unset($failing['recipient']['deliveryAddress']['city']);

    Saloon::fake([
        SearchOrders::class => MockResponse::make(['orders' => [demoAmazonOrder(), $failing]]),
        SearchCatalogItems::class => MockResponse::make(['numberOfResults' => 0, 'items' => []]),
    ]);

    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    demoAmazonImport($this->amazon->fresh());

    expect(Shipment::count())->toBe(1);

    $everything = implode("\n", $logged).json_encode($this->amazon->fresh()->getAttributes());

    foreach (demoAmazonBuyerData() as $buyerData) {
        expect($everything)->not->toContain($buyerData);
    }
});

it('keeps the whole address outside demo', function (): void {
    Saloon::fake([
        SearchOrders::class => MockResponse::make(['orders' => [demoAmazonOrder()]]),
        SearchCatalogItems::class => MockResponse::make(['numberOfResults' => 0, 'items' => []]),
    ]);

    demoAmazonImport($this->amazon);

    $shipment = Shipment::where('shipment_reference', '111-0000000-0000001')->firstOrFail();

    expect($shipment->address1)->toBe('4821 Private Lane')
        ->and($shipment->first_name)->toBe('Rhonda')
        ->and($shipment->isAddressWithheld())->toBeFalse()
        ->and($shipment->residential)->toBeFalse();
});

it('tells a carrier a withheld address is a residence with a name that names no one', function (): void {
    $this->app['env'] = 'demo';
    $package = demoWithheldPackage();
    $package->shipment->forceFill(['residential' => false])->save();

    $address = AddressData::fromShipment($package->shipment->fresh());

    expect($address->isWithheld())->toBeTrue()
        ->and($address->isResidential())->toBeTrue()
        ->and($address->firstName.' '.$address->lastName)->toBe('Name withheld')
        ->and($address->company)->toBeNull()
        ->and($address->phone)->toBeNull()
        ->and(RateRequest::fromPackage($package)->residential)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Address validation
|--------------------------------------------------------------------------
*/

it('runs no validator on a withheld address in demo, and leaves the shipment unheld', function (): void {
    $this->app['env'] = 'demo';
    $shipment = demoWithheldPackage()->shipment;

    expect(app(ShipmentValidationPlan::class)->validatorsFor($shipment))->toBe([]);

    $shipment->validateAddress();
    $shipment->refresh();

    expect($shipment->deliverability)->toBe(Deliverability::NotChecked)
        ->and($shipment->validation_attempted_at)->toBeNull()
        ->and($shipment->validationAnswers()->count())->toBe(0);

    $this->artisan('shipments:validate')->assertSuccessful();

    expect($shipment->fresh()->deliverability)->not->toBe(Deliverability::No);
});

it('treats the withheld text as an ordinary address outside demo', function (string $environment): void {
    $this->app['env'] = $environment;
    $shipment = demoWithheldPackage()->shipment;
    $shipment->forceFill(['residential' => false])->save();
    $shipment = $shipment->fresh();

    $address = AddressData::fromShipment($shipment);

    expect($shipment->isAddressWithheld())->toBeFalse()
        ->and(app(ShipmentValidationPlan::class)->validatorsFor($shipment))->not->toBe([])
        ->and($address->isWithheld())->toBeFalse()
        ->and($address->residential)->toBeFalse()
        ->and($address->streetAddress)->toBe(Shipment::WITHHELD_ADDRESS_LINE);
})->with(['production', 'testing']);

/*
|--------------------------------------------------------------------------
| Offers and the postage environment
|--------------------------------------------------------------------------
*/

it('redeems in demo an offer quoted on the sandbox for an order read from production', function (): void {
    $this->app['env'] = 'demo';

    Saloon::fake([
        SearchOrders::class => MockResponse::make(['orders' => [demoAmazonOrder()]]),
        SearchCatalogItems::class => MockResponse::make(['numberOfResults' => 0, 'items' => []]),
        GetShippingRates::class => demoAmazonRatesResponse(),
        PurchaseShipment::class => MockResponse::make(['payload' => [
            'shipmentId' => 'amzn1.sid.demo',
            'packageDocumentDetails' => [[
                'packageClientReferenceId' => '1',
                'trackingId' => 'TBA000000000001',
                'packageDocuments' => [['type' => 'LABEL', 'format' => 'ZPL', 'contents' => base64_encode('^XA^XZ')]],
            ]],
        ]]),
    ]);

    demoAmazonImport($this->amazon);

    $shipment = Shipment::where('shipment_reference', '111-0000000-0000001')->firstOrFail();
    $method = ShippingMethod::factory()->create();
    ShippingMethodPostageSource::factory()->amazon()->for($method)->create();
    $shipment->update(['shipping_method_id' => $method->id]);

    $package = Package::factory()->create(['shipment_id' => $shipment->id, 'weight' => 0.75, 'length' => 9, 'width' => 6, 'height' => 2]);
    $item = $shipment->shipmentItems->first();
    PackageItem::factory()->create(['package_id' => $package->id, 'shipment_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => 2]);
    $package = $package->fresh();

    $rate = (new AmazonBuyShippingAdapter)->getRates(RateRequest::fromPackage($package), [])->first();

    expect($rate)->not->toBeNull()
        ->and(ShippingOffer::where('public_id', $rate->offerId)->value('environment'))->toBe(SourceEnvironment::Sandbox);

    $result = app(EloquentPackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(
        selectedRate: $rate,
        labelFormat: 'zpl',
        labelDpi: 203,
    ));

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);

    Saloon::assertSent(fn (Request $request, Response $response): bool => $request instanceof SearchOrders
        && str_starts_with($response->getPendingRequest()->getUrl(), DEMO_AMAZON_PRODUCTION_HOST.'/'));

    foreach ([GetShippingRates::class, PurchaseShipment::class] as $postage) {
        Saloon::assertSent(fn (Request $request, Response $response): bool => $request instanceof $postage
            && str_starts_with($response->getPendingRequest()->getUrl(), DEMO_AMAZON_SANDBOX_HOST.'/'));
        Saloon::assertNotSent(fn (Request $request, Response $response): bool => $request instanceof $postage
            && str_starts_with($response->getPendingRequest()->getUrl(), DEMO_AMAZON_PRODUCTION_HOST.'/'));
    }
});

it('quotes Amazon Shipping for an order from another channel on the sandbox in demo', function (): void {
    $this->app['env'] = 'demo';
    Cache::put('amazon_sp_api_access_token_'.md5('external-refresh-token'), 'external-access-token', 3600);

    $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create([
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => ['refresh_token' => 'external-refresh-token'],
    ]);
    CarrierAccountScope::create(['data_source_id' => $connection->id]);

    Saloon::fake([GetShippingRates::class => externalRatesResponse()]);

    $rates = (new AmazonShippingAdapter)->getRates(RateRequest::fromPackage(externalPackage(createShopifyDataSource())), ['std-us-swa-mfn']);

    expect($rates)->not->toBeEmpty();

    Saloon::assertSent(fn (Request $request, Response $response): bool => $request instanceof GetShippingRates
        && $request->channelType() === 'EXTERNAL'
        && str_starts_with($response->getPendingRequest()->getUrl(), DEMO_AMAZON_SANDBOX_HOST.'/'));
});

/*
|--------------------------------------------------------------------------
| The Ship page
|--------------------------------------------------------------------------
*/

it('says on the ship page that Amazon sandbox rates are canned, in demo only', function (string $environment, bool $noted): void {
    $this->app['env'] = $environment;
    $this->actingAs(User::factory()->admin()->create());

    Saloon::fake([GetShippingRates::class => demoAmazonRatesResponse()]);

    $package = demoWithheldPackage($this->amazon);
    ShippingMethodPostageSource::factory()->amazon()->for($package->shipment->shippingMethod)->create();

    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);

    expect(collect($component->get('rateOptions'))->pluck('serviceName')->all())->toContain('Amazon Shipping Ground');

    $noted
        ? $component->assertNotified('Amazon sandbox rates')
        : $component->assertNotNotified('Amazon sandbox rates');
})->with([
    'demo' => ['demo', true],
    'production' => ['production', false],
]);
