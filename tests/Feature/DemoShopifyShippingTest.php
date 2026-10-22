<?php

use App\Contracts\BlindPurchaseSource;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Filament\Pages\Ship;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\StoreWriteGuard;
use Livewire\Livewire;
use Mockery\MockInterface;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * `live-store-demo/02`: Shopify has no sandbox, so in Demo a blind purchase is
 * offered and bought only on a development store, read from the store's plan.
 * Production and Dev never ask.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/*
|--------------------------------------------------------------------------
| The check
|--------------------------------------------------------------------------
*/

it('allows every write without asking the store outside demo', function (string $environment): void {
    $this->app['env'] = $environment;
    Saloon::fake([]);

    $guard = app(StoreWriteGuard::class);

    expect($guard->allowsWritesTo(createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token'])))->toBeTrue()
        ->and($guard->allowsWritesTo(DataSource::factory()->create(['source_type' => AmazonSource::class])))->toBeTrue();

    Saloon::assertNothingSent();
})->with(['production', 'local', 'testing']);

it('allows writes in demo only to a store whose plan says development', function (array $plan, bool $allowed): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan($plan);

    expect(app(StoreWriteGuard::class)->allowsWritesTo(createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token'])))->toBe($allowed);
})->with([
    'partnerDevelopment, previewing a paid plan' => [['partnerDevelopment' => true, 'publicDisplayName' => 'Plus'], true],
    'named Development' => [['partnerDevelopment' => false, 'publicDisplayName' => 'Development'], true],
    'a live store' => [['partnerDevelopment' => false, 'publicDisplayName' => 'Basic'], false],
]);

it('counts a plan that cannot be read as live', function (MockResponse $response): void {
    $this->app['env'] = 'demo';
    Saloon::fake([GraphQL::class => $response]);

    expect(app(StoreWriteGuard::class)->allowsWritesTo(createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token'])))->toBeFalse();
})->with([
    'a server error' => fn (): MockResponse => MockResponse::make(['errors' => 'Internal error'], 500),
    'access denied' => fn (): MockResponse => MockResponse::make(['errors' => [['message' => 'Access denied for plan field.']]]),
    'no plan' => fn (): MockResponse => MockResponse::make(['data' => ['shop' => null]]),
]);

it('reads the plan once and caches it per connection', function (): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan(['partnerDevelopment' => true, 'publicDisplayName' => 'Plus']);
    $guard = app(StoreWriteGuard::class);
    $connection = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $guard->allowsWritesTo($connection);
    $guard->allowsWritesTo($connection);

    Saloon::assertSentCount(1);
});

it('never writes to an Amazon store in demo, and always to a database connection', function (): void {
    $this->app['env'] = 'demo';
    Saloon::fake([]);
    $guard = app(StoreWriteGuard::class);

    expect($guard->allowsWritesTo(DataSource::factory()->create(['source_type' => AmazonSource::class])))->toBeFalse()
        ->and($guard->allowsWritesTo(DataSource::factory()->create(['source_type' => DatabaseSource::class])))->toBeTrue();

    Saloon::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Offering and buying
|--------------------------------------------------------------------------
*/

it('offers no Shopify Shipping for a live store in demo, and says why', function (): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan(['partnerDevelopment' => false, 'publicDisplayName' => 'Basic']);
    $package = liveStoreDemoPackage();
    $source = registerLiveStoreDemoBlindSource();

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->blindPurchaseOffers)->toBe([])
        ->and($options->exclusions)->toContain([
            'carrier' => 'Shopify Shipping',
            'source' => 'Shopify',
            'reason' => 'Shopify Shipping is not available in demo mode on a live store.',
        ]);

    $source->shouldNotHaveReceived('blindPurchaseOffers');
});

it('tells the packer on the Ship page why Shopify Shipping is missing', function (): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan(['partnerDevelopment' => false, 'publicDisplayName' => 'Basic']);
    $package = liveStoreDemoPackage();
    registerLiveStoreDemoBlindSource();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSet('blindPurchaseOffers', [])
        ->assertNotified('Shopify Shipping excluded');
});

it('offers Shopify Shipping for a development store in demo', function (): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan(['partnerDevelopment' => true, 'publicDisplayName' => 'Plus']);
    $package = liveStoreDemoPackage();
    $source = registerLiveStoreDemoBlindSource();
    $source->shouldReceive('createShipment')->once()->andReturn(liveStoreDemoShipResponse());

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);
    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(blindOffer: liveStoreDemoBlindOffer()),
    );

    expect($options->blindPurchaseOffers)->toHaveCount(1)
        ->and($options->blindPurchaseOffers[0]['id'])->toBe('Shopify:auto')
        ->and($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('refuses a purchase a stale Ship page sends for a live store in demo', function (): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan(['partnerDevelopment' => false, 'publicDisplayName' => 'Basic']);
    $package = liveStoreDemoPackage();
    $source = registerLiveStoreDemoBlindSource();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(blindOffer: liveStoreDemoBlindOffer()),
    );

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Shopify Shipping is not available in demo mode on a live store.')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('keeps auto-ship and batch ship from buying the sole blind choice on a live store in demo', function (): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan(['partnerDevelopment' => false, 'publicDisplayName' => 'Basic']);
    $package = liveStoreDemoPackage();
    $source = registerLiveStoreDemoBlindSource();

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('keeps a rule that selects Shopify Shipping from buying it on a live store in demo', function (): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan(['partnerDevelopment' => false, 'publicDisplayName' => 'Basic']);
    $package = liveStoreDemoPackage();
    $source = registerLiveStoreDemoBlindSource();

    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => null,
        'any_service' => true,
    ]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('auto-ships the sole blind choice on a development store in demo', function (): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan(['partnerDevelopment' => true, 'publicDisplayName' => 'Plus']);
    $package = liveStoreDemoPackage();
    $source = registerLiveStoreDemoBlindSource();
    $source->shouldReceive('createShipment')->once()->andReturn(liveStoreDemoShipResponse());

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('treats a store whose plan cannot be read as live when offering', function (): void {
    $this->app['env'] = 'demo';
    Saloon::fake([GraphQL::class => MockResponse::make(['errors' => 'Internal error'], 500)]);
    $package = liveStoreDemoPackage();
    registerLiveStoreDemoBlindSource();

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->blindPurchaseOffers)->toBe([])
        ->and(collect($options->exclusions)->pluck('reason'))->toContain('Shopify Shipping is not available in demo mode on a live store.');
});

it('offers and buys Shopify Shipping without a plan lookup outside demo', function (string $environment): void {
    $this->app['env'] = $environment;
    Saloon::fake([]);
    $package = liveStoreDemoPackage();
    $source = registerLiveStoreDemoBlindSource();
    $source->shouldReceive('createShipment')->once()->andReturn(liveStoreDemoShipResponse());

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);
    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(blindOffer: liveStoreDemoBlindOffer()),
    );

    expect($options->blindPurchaseOffers)->toHaveCount(1)
        ->and($result->success)->toBeTrue();

    Saloon::assertNothingSent();
})->with(['production', 'local']);

it('sends no purchase mutation for a live store in demo, whoever asks', function (): void {
    $this->app['env'] = 'demo';
    $package = liveStoreDemoPackage();
    $queries = [];
    Saloon::fake([GraphQL::class => function (PendingRequest $request) use (&$queries): MockResponse {
        $queries[] = (string) ($request->body()?->all()['query'] ?? '');

        return MockResponse::make(['data' => ['shop' => ['plan' => ['partnerDevelopment' => false, 'publicDisplayName' => 'Basic']]]]);
    }]);

    // Straight to the adapter, past rating and the workflow's re-derived offer.
    $response = (new ShopifyAdapter)->createShipment(ShipRequest::fromPackageAndBlindOffer($package, liveStoreDemoBlindOffer()));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toBe('Shopify Shipping is not available in demo mode on a live store.')
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('plan')
        ->and($queries[0])->not->toContain('shippingLabelPurchase');
});

/*
|--------------------------------------------------------------------------
| The connection's edit page
|--------------------------------------------------------------------------
*/

it('shows on a Shopify connection in demo whether its store is a development store', function (array $plan, string $shown, string $notShown): void {
    $this->app['env'] = 'demo';
    fakeLiveStoreDemoPlan($plan);
    $connection = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->assertSee($shown)
        ->assertDontSee($notShown);
})->with([
    'a development store' => [
        ['partnerDevelopment' => true, 'publicDisplayName' => 'Plus'],
        'Shopify Shipping is available in demo mode.',
        'Not a development store',
    ],
    'a live store' => [
        ['partnerDevelopment' => false, 'publicDisplayName' => 'Basic'],
        'Shopify Shipping is off in demo mode',
        'Shopify Shipping is available in demo mode.',
    ],
]);

it('shows no development-store status and asks nothing outside demo', function (): void {
    fakeLiveStoreDemoPlan(['partnerDevelopment' => true, 'publicDisplayName' => 'Plus']);
    $connection = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    Livewire::test(EditDataSource::class, ['record' => $connection->id])
        ->assertDontSee('Development store')
        ->assertDontSee('Not a development store');

    Saloon::assertNotSent(fn (GraphQL $request): bool => str_contains((string) ($request->body()->all()['query'] ?? ''), 'plan {'));
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A store answering the plan query with `$plan`, and any other read the page
 * makes with an empty answer.
 *
 * @param  array{partnerDevelopment: bool, publicDisplayName: string}  $plan
 */
function fakeLiveStoreDemoPlan(array $plan): void
{
    Saloon::fake([GraphQL::class => function (PendingRequest $request) use ($plan): MockResponse {
        $query = (string) ($request->body()?->all()['query'] ?? '');

        if (str_contains($query, 'plan {')) {
            return MockResponse::make(['data' => ['shop' => ['plan' => $plan]]]);
        }

        if (str_contains($query, 'currentAppInstallation')) {
            return MockResponse::make(['data' => ['currentAppInstallation' => ['accessScopes' => []]]]);
        }

        throw new LogicException('Unexpected Shopify request: '.$query);
    }]);
}

/**
 * A package from a Shopify connection that sells postage to packers and
 * automation, on a method whose only choice is Shopify's own.
 */
function liveStoreDemoPackage(): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shippingMethod = ShippingMethod::factory()->create();
    ShippingMethodPostageSource::factory()->shopify()->any()->for($shippingMethod)->create();

    $shipment = Shipment::factory()->for($shippingMethod)->create([
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);

    $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
    allowBlindPurchase($package);

    return $package;
}

function liveStoreDemoBlindOffer(): BlindPurchaseOffer
{
    return new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Shopify Shipping',
        serviceCode: 'auto',
        selectionLabel: "Shopify's choice",
    );
}

/**
 * A stand-in for `ShopifyAdapter`, registered under its name, so what is under
 * test is the path and not Shopify.
 */
function registerLiveStoreDemoBlindSource(): MockInterface
{
    $source = Mockery::mock(BlindPurchaseSource::class);
    $source->shouldReceive('getCarrierName')->andReturn('Shopify');
    $source->shouldReceive('isConfigured')->andReturnTrue();
    $source->shouldReceive('offerCapability')->andReturn(ServiceCapability::Unguaranteed);
    $source->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $source->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::Separate);
    $source->shouldReceive('blindPurchaseOffers')->andReturn(collect([liveStoreDemoBlindOffer()]));

    app(CarrierRegistry::class)->registerInstance('Shopify', $source);

    return $source;
}

function liveStoreDemoShipResponse(): ShipResponse
{
    return new ShipResponse(
        success: true,
        trackingNumber: '9400111899223197428490',
        cost: null,
        carrier: 'USPS',
        service: null,
        labelData: base64_encode('LABEL-BYTES'),
        labelFormat: 'pdf',
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: DataSource::where('source_type', 'like', '%ShopifySource')->value('id'),
    );
}
