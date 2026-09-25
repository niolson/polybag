<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\ContentClass;
use App\Enums\OfferRejection;
use App\Enums\PackageStatus;
use App\Enums\ShippingRuleAction;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\RateQuote;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\PostageSources\OfferStore;
use App\Services\ShippingRateService;
use Database\Seeders\CarrierSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/**
 * USPS Media Mail, sold directly and only to a Package whose every item is a
 * product the seller marked as media — `carrier-catalog-reset/02`, ADR-0006
 * decisions 10 and 11.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    Cache::flush();

    $this->usps = Carrier::factory()->usps()->create(['active' => true]);
    $this->groundAdvantage = CarrierService::factory()->uspsGroundAdvantage()->for($this->usps)->create();
    $this->mediaMail = CarrierService::factory()->uspsMediaMail()->for($this->usps)->create();

    $this->method = ShippingMethod::factory()->create();
    $this->method->carrierServices()->attach([$this->groundAdvantage->id, $this->mediaMail->id]);
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A package holding one of each product, on the Media Mail method unless told
 * otherwise, quoted by the fake USPS adapter on a USPS account.
 */
function mediaMailPackage(array $products, bool $onMethod = true): Package
{
    $account = CarrierAccount::factory()->create(['carrier_id' => test()->usps->id, 'active' => true]);
    CarrierAccountScope::factory()->forAccount($account)->global()->create();

    $shipment = Shipment::factory()->create([
        'shipping_method_id' => $onMethod ? test()->method->id : null,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);

    foreach ($products as $product) {
        $package->packageItems()->create(['product_id' => $product->id, 'quantity' => 1]);
    }

    app(CarrierRegistry::class)->registerInstance('USPS', new FakeCarrierAdapter('USPS'));

    return $package;
}

function qualifyingMediaMailPackage(): Package
{
    return mediaMailPackage([Product::factory()->media()->create(), Product::factory()->media()->create()]);
}

it('quotes Media Mail to a package whose items are all media, behind an offer naming its service and carrier', function (): void {
    $package = qualifyingMediaMailPackage();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id)->keyBy('serviceCode');

    expect($rates->keys()->all())->toContain('MEDIA_MAIL', 'USPS_GROUND_ADVANTAGE')
        ->and($rates['MEDIA_MAIL']->carrierServiceId)->toBe($this->mediaMail->id)
        ->and($rates['MEDIA_MAIL']->carrierId)->toBe($this->usps->id);

    $offer = ShippingOffer::where('public_id', $rates['MEDIA_MAIL']->offerId)->sole();

    expect($offer->carrier_service_id)->toBe($this->mediaMail->id)
        ->and($offer->carrier_id)->toBe($this->usps->id)
        ->and($offer->carrierService->is($this->mediaMail))->toBeTrue()
        ->and($offer->carrierRow->is($this->usps))->toBeTrue();
});

it('never quotes Media Mail to a package that does not qualify, and leaves no offer or quote behind', function (Closure $products): void {
    $package = mediaMailPackage($products());

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates->pluck('serviceCode')->all())->toBe(['USPS_GROUND_ADVANTAGE'])
        ->and(ShippingOffer::where('package_id', $package->id)->where('service_code', 'MEDIA_MAIL')->exists())->toBeFalse()
        ->and(RateQuote::where('package_id', $package->id)->where('service_code', 'MEDIA_MAIL')->exists())->toBeFalse();
})->with([
    'one item is not media' => fn (): array => [Product::factory()->media()->create(), Product::factory()->create()],
    'no items at all' => fn (): array => [],
]);

it('never quotes Media Mail to a package that does not qualify when it has no shipping method', function (): void {
    $package = mediaMailPackage([Product::factory()->create()], onMethod: false);

    $codes = app(ShippingRateService::class)->getShippingRates($package->id)->pluck('serviceCode');

    expect($codes)->toContain('USPS_GROUND_ADVANTAGE')
        ->and($codes)->not->toContain('MEDIA_MAIL');
});

it('quotes a direct USPS Media Mail rate, and never Library Mail', function (): void {
    // The real adapter against the logged sandbox shape, so the allow-list and
    // the catalog identity are proved together.
    app(CarrierRegistry::class)->reset();
    createUspsAccount();

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make([
            'pricingOptions' => [[
                'shippingOptions' => [[
                    'rateOptions' => array_map(fn (array $rate): array => [
                        'totalBasePrice' => $rate[3],
                        'commitment' => ['name' => '2-8 Days', 'scheduleDeliveryDate' => '2026-09-26'],
                        'rates' => [[
                            'mailClass' => $rate[0],
                            'rateIndicator' => $rate[1],
                            'processingCategory' => $rate[2],
                            'destinationEntryFacilityType' => 'NONE',
                            'description' => "{$rate[0]} {$rate[2]}",
                        ]],
                    ], [
                        ['USPS_GROUND_ADVANTAGE', 'SP', 'MACHINABLE', 7.99],
                        ['MEDIA_MAIL', 'SP', 'MACHINABLE', 5.13],
                        ['MEDIA_MAIL', 'SP', 'NONSTANDARD', 5.13],
                        ['LIBRARY_MAIL', 'SP', 'MACHINABLE', 4.87],
                    ]),
                ]],
            ]],
        ]),
    ]);

    $shipment = Shipment::factory()->for($this->method)->create(['postal_code' => '90210']);
    $package = Package::factory()->for($shipment)->create(['weight' => 1.15, 'height' => 2, 'width' => 6, 'length' => 9]);
    $package->packageItems()->create(['product_id' => Product::factory()->media()->create()->id, 'quantity' => 1]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);
    $mediaMail = $rates->where('serviceCode', 'MEDIA_MAIL');

    expect($mediaMail)->toHaveCount(2)
        ->and($mediaMail->pluck('carrierServiceId')->unique()->all())->toBe([$this->mediaMail->id])
        ->and($rates->pluck('serviceCode'))->not->toContain('LIBRARY_MAIL')
        ->and($mediaMail->every(fn (RateResponse $rate): bool => $rate->offerId !== null))->toBeTrue();
});

it('lets automation choose Media Mail for a qualifying package when it is the cheapest acceptable rate', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = qualifyingMediaMailPackage();

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($result->selectedRate->serviceCode)->toBe('MEDIA_MAIL')
        ->and($package->fresh()->activeLabel->carrier_service_id)->toBe($this->mediaMail->id);
});

it('does not let a Use rule naming Media Mail buy it for a package that does not qualify', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = mediaMailPackage([Product::factory()->media()->create(), Product::factory()->create()]);

    ShippingRule::factory()->create([
        'shipping_method_id' => $this->method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $this->mediaMail->id,
    ]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    // The rule's choice is dropped as rate shopping would drop it, and rate
    // shopping buys what the package may be sold.
    expect($result->success)->toBeTrue()
        ->and($result->selectedRate->serviceCode)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($package->fresh()->activeLabel->carrier_service_id)->toBe($this->groundAdvantage->id);
});

it('lets a Use rule naming Media Mail buy it for a package that qualifies', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = qualifyingMediaMailPackage();

    ShippingRule::factory()->create([
        'shipping_method_id' => $this->method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $this->mediaMail->id,
    ]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($result->selectedRate->serviceCode)->toBe('MEDIA_MAIL')
        ->and($package->fresh()->activeLabel->carrier_service_id)->toBe($this->mediaMail->id);
});

it('records the catalog service from the offer, not from what the browser sent back', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = qualifyingMediaMailPackage();

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);
    $groundAdvantage = collect($options->rateOptions)->firstWhere('serviceCode', 'USPS_GROUND_ADVANTAGE');

    // The browser names the Ground Advantage offer and lies about which
    // catalog service and carrier it is.
    $tampered = RateResponse::fromArray([
        ...$groundAdvantage,
        'carrierServiceId' => $this->mediaMail->id,
        'carrierId' => Carrier::factory()->create()->id,
    ]);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $tampered, userId: $user->id),
    );

    expect($result->success)->toBeTrue()
        ->and($result->selectedRate->carrierServiceId)->toBe($this->groundAdvantage->id)
        ->and($result->selectedRate->carrierId)->toBe($this->usps->id)
        ->and($package->fresh()->activeLabel->carrier_service_id)->toBe($this->groundAdvantage->id);
});

it('retires a Media Mail offer once a product in the package stops being media', function (): void {
    $this->actingAs(User::factory()->create());
    $media = Product::factory()->media()->create();
    $package = mediaMailPackage([$media]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);
    $quoted = RateResponse::fromArray(collect($options->rateOptions)->firstWhere('serviceCode', 'MEDIA_MAIL'));

    $media->update(['is_media' => false]);

    $inspection = app(OfferStore::class)->inspect($package->fresh(), $quoted->offerId);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(selectedRate: $quoted),
    );

    expect($inspection->rejection)->toBe(OfferRejection::PackageChanged)
        ->and($result->success)->toBeFalse()
        ->and($result->requiresRequote)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('seeds USPS Media Mail requiring media contents, reaching PO Boxes and military addresses', function (): void {
    $this->mediaMail->delete();

    $this->seed(CarrierSeeder::class);

    $seeded = CarrierService::query()->where('carrier_id', $this->usps->id)->where('service_code', 'MEDIA_MAIL')->sole();

    expect($seeded->required_contents)->toBe(ContentClass::Media)
        ->and($seeded->can_ship_to_po_boxes)->toBeTrue()
        ->and($seeded->can_ship_to_military_addresses)->toBeTrue()
        ->and(CarrierService::query()->where('service_code', 'LIBRARY_MAIL')->exists())->toBeFalse();
});

it('restores the media requirement on a Media Mail row that lacks it', function (): void {
    $this->mediaMail->update(['required_contents' => null]);

    $this->seed(CarrierSeeder::class);

    expect($this->mediaMail->fresh()->required_contents)->toBe(ContentClass::Media);
});

it('marks a product as media on its form, and leaves it off by default', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Plain Widget', 'sku' => 'WDG-001'])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Field Guide', 'sku' => 'BOOK-001', 'is_media' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('sku', 'WDG-001')->sole()->is_media)->toBeFalse()
        ->and(Product::where('sku', 'BOOK-001')->sole()->is_media)->toBeTrue();
});

it('marks products as media, and back, in bulk', function (): void {
    $this->actingAs(User::factory()->manager()->create());
    $products = Product::factory()->count(2)->create();
    $untouched = Product::factory()->create();

    Livewire::test(ListProducts::class)
        ->selectTableRecords($products)
        ->callAction(TestAction::make('mark-media')->table()->bulk());

    expect($products->map(fn (Product $product): bool => $product->fresh()->is_media)->all())->toBe([true, true])
        ->and($untouched->fresh()->is_media)->toBeFalse();

    Livewire::test(ListProducts::class)
        ->selectTableRecords($products)
        ->callAction(TestAction::make('mark-not-media')->table()->bulk());

    expect($products->map(fn (Product $product): bool => $product->fresh()->is_media)->all())->toBe([false, false]);
});

it('filters the product table by the media flag', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $media = Product::factory()->media()->create();
    $other = Product::factory()->create();

    Livewire::test(ListProducts::class)
        ->filterTable('is_media', true)
        ->assertCanSeeTableRecords([$media])
        ->assertCanNotSeeTableRecords([$other]);
});

/*
|--------------------------------------------------------------------------
| Through Shopify — carrier-catalog-reset/04
|--------------------------------------------------------------------------
|
| Media Mail's requirement binds every source that sells it. Shopify's own
| Media Mail row carries it, and the adapter reads it before advertising.
|
*/

/**
 * A package on a Shopify order whose shipping method lists Shopify's Media
 * Mail and Shopify's choice, with the real adapter registered.
 */
function shopifyMediaMailPackage(array $products): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shopify = Carrier::factory()->shopify()->create();
    $auto = CarrierService::factory()->for($shopify)->create(['name' => "Shopify's choice", 'service_code' => 'auto']);
    $mediaMail = CarrierService::factory()->for($shopify)->create([
        'name' => "Shopify's USPS Media Mail",
        'service_code' => 'usps:MediaMail',
        'required_contents' => ContentClass::Media,
    ]);

    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach([$auto->id, $mediaMail->id]);

    $shipment = Shipment::factory()->for($method)->create([
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);

    $package = Package::factory()->for($shipment)->create([
        'weight' => 2.0,
        'status' => PackageStatus::Unshipped,
    ]);

    foreach ($products as $product) {
        $package->packageItems()->create(['product_id' => $product->id, 'quantity' => 1]);
    }

    allowBlindPurchase($package);
    app(CarrierRegistry::class)->registerInstance('Shopify', new ShopifyAdapter);

    return $package->fresh();
}

function shopifyMediaMailOffer(Package $package): BlindPurchaseOffer
{
    return new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Shopify Shipping',
        serviceCode: 'usps:MediaMail',
        selectionLabel: "Shopify's USPS Media Mail",
        postageDataSourceId: $package->shipment->data_source_id,
    );
}

it('advertises Shopify Media Mail to a package whose items are all media', function (): void {
    $package = shopifyMediaMailPackage([Product::factory()->media()->create()]);

    $offers = app(ShippingRateService::class)->blindPurchaseOffersFor($package);

    expect($offers->pluck('serviceCode')->all())->toBe(['auto', 'usps:MediaMail']);
});

it('does not advertise Shopify Media Mail to a package that does not qualify', function (): void {
    $package = shopifyMediaMailPackage([Product::factory()->media()->create(), Product::factory()->create()]);

    $offers = app(ShippingRateService::class)->blindPurchaseOffersFor($package);

    expect($offers->pluck('serviceCode')->all())->toBe(['auto']);
});

it('refuses a stale Shopify Media Mail selection at purchase once the package no longer qualifies', function (): void {
    $this->actingAs($user = User::factory()->create());
    $product = Product::factory()->media()->create();
    $package = shopifyMediaMailPackage([$product]);

    // Advertised while the package qualified, then the product is unmarked.
    $stale = shopifyMediaMailOffer($package);
    $product->update(['is_media' => false]);

    $result = app(PackageShippingWorkflow::class)->ship($package->fresh(), new PackageShippingRequest(
        blindOffer: $stale,
        userId: $user->id,
    ));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Offer No Longer Available')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('does not let a rule pre-selecting Shopify Media Mail buy it for a package that does not qualify', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = shopifyMediaMailPackage([Product::factory()->create()]);
    $method = $package->shipment->shippingMethod;
    $mediaMail = CarrierService::where('service_code', 'usps:MediaMail')->sole();

    // Media Mail alone, so nothing else could be bought in its place.
    $method->carrierServices()->sync([$mediaMail->id]);

    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $mediaMail->id,
    ]);

    $adapter = Mockery::mock(ShopifyAdapter::class)->makePartial();
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('Shopify', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Shipping Error')
        ->and($result->message)->toBe('No shipping rates available for this package.')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('seeds Shopify Media Mail requiring media contents, and restores the requirement on every sync', function (): void {
    $this->seed(CarrierSeeder::class);

    $shopifyMediaMail = CarrierService::query()
        ->whereHas('carrier', fn ($query) => $query->where('name', ShopifyAdapter::CARRIER_NAME))
        ->where('service_code', 'usps:MediaMail')
        ->sole();

    expect($shopifyMediaMail->required_contents)->toBe(ContentClass::Media);

    $shopifyMediaMail->update(['required_contents' => null]);
    $this->seed(CarrierSeeder::class);

    expect($shopifyMediaMail->fresh()->required_contents)->toBe(ContentClass::Media);
});
