<?php

use App\Contracts\BlindPurchaseSource;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\AmazonChannelType;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\CustomsTermsOrigin;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\PostageSetting;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Enums\ServiceEvidence;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Enums\UnlistedServices;
use App\Filament\Pages\Ship;
use App\Jobs\GenerateLabelJob;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingOffer;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\AmazonBuyShippingService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Customs\CustomsTermsSnapshot;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\Shipping\DutiesTermsFilter;
use Livewire\Livewire;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| international-customs-terms/09 — source-decided terms
|--------------------------------------------------------------------------
|
| Amazon Buy Shipping and Shopify Shipping decide the duties terms of the
| label themselves. A person may buy them into the EU, GB, NO and AU; nothing
| unattended does, on either path: quoted offers through
| RateSelector::selectForAutomation(), blind purchases through
| UnattendedRateSelector. ADR-0008 decisions 5 and 8.
|
*/

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs($this->user = User::factory()->admin()->create());
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * @param  array<string, mixed>  $shipmentAttributes
 */
function sdShipment(ShippingMethod $method, string $country, array $shipmentAttributes = []): Shipment
{
    $destination = match ($country) {
        'DE' => ['city' => 'Berlin', 'state_or_province' => null, 'postal_code' => '10117'],
        'GB' => ['city' => 'London', 'state_or_province' => null, 'postal_code' => 'SW1A 1AA'],
        'AU' => ['city' => 'Sydney', 'state_or_province' => 'NSW', 'postal_code' => '2000'],
        'NO' => ['city' => 'Oslo', 'state_or_province' => null, 'postal_code' => '0150'],
        'CA' => ['city' => 'Toronto', 'state_or_province' => 'ON', 'postal_code' => 'M5V 2T6'],
        default => [],
    };

    return Shipment::factory()->create([
        'shipping_method_id' => $method->id,
        'country' => $country,
        'company' => null,
        ...$destination,
        ...$shipmentAttributes,
    ]);
}

function sdPackedPackage(Shipment $shipment): Package
{
    $product = Product::factory()->create(['weight' => 1.5]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'value' => 20.0,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'height' => 10,
        'width' => 8,
        'length' => 6,
        'status' => PackageStatus::Unshipped,
    ]);

    $package->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package;
}

/**
 * A package whose method sells Amazon Buy Shipping under any service, bound
 * for $country. The client has set no EU terms.
 */
function sdAmazonPackage(string $country, UnlistedServices $amazon = UnlistedServices::Any): Package
{
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Ground',
        'service_code' => 'GROUND',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create(['name' => 'Standard', 'excludes_late_rates' => false]);
    $method->carrierServices()->attach($service->id);
    ShippingMethodPostageSource::factory()->amazon()->create([
        'shipping_method_id' => $method->id,
        'unlisted_services' => $amazon,
    ]);

    return sdPackedPackage(sdShipment($method, $country));
}

function sdAmazonRate(float $price = 4.00): RateResponse
{
    return new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: 'GROUND',
        serviceName: 'Ground',
        price: $price,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            externalCarrierId: 'USPS',
            externalServiceId: 'USPS_GROUND_ADVANTAGE',
        ),
        carrierServiceId: CarrierService::where('service_code', 'GROUND')->value('id'),
    );
}

function sdRegisterAmazonQuote(): MockInterface
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::FusedIntoLabel);
    $adapter->shouldReceive('getRates')->andReturn(collect([sdAmazonRate()]));
    $adapter->shouldReceive('createShipment')->andReturn(ShipResponse::success(
        trackingNumber: 'AMZ123',
        cost: 4.00,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    return $adapter;
}

function sdAutoShip(User $user, Package $package): mixed
{
    return app(PackageShippingWorkflow::class)->autoShip(
        $package->fresh(),
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );
}

/**
 * A package whose method lets Shopify choose, bound for $country, from a
 * connection that sells postage to automation.
 */
function sdShopifyPackage(string $country): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);
    $method = ShippingMethod::factory()->create();
    ShippingMethodPostageSource::factory()->shopify()->any()->for($method)->create();

    $package = sdPackedPackage(sdShipment($method, $country, [
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]));
    allowBlindPurchase($package);

    return $package;
}

function sdShopifyOffer(): BlindPurchaseOffer
{
    return new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Shopify Shipping',
        serviceCode: 'auto',
        selectionLabel: "Shopify's choice",
    );
}

function sdShopifyShipResponse(): ShipResponse
{
    return new ShipResponse(
        success: true,
        trackingNumber: '9400111899223197428490',
        cost: null,
        carrier: 'USPS',
        service: null,
        serviceEvidence: ServiceEvidence::Unknown,
        labelData: base64_encode('LABEL-BYTES'),
        labelFormat: 'pdf',
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: DataSource::where('source_type', ShopifySource::class)->value('id'),
    );
}

function sdRegisterShopify(): MockInterface
{
    $source = Mockery::mock(BlindPurchaseSource::class);
    $source->shouldReceive('getCarrierName')->andReturn('Shopify');
    $source->shouldReceive('isConfigured')->andReturnTrue();
    $source->shouldReceive('offerCapability')->andReturn(ServiceCapability::Unguaranteed);
    $source->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $source->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::Separate);
    $source->shouldReceive('blindPurchaseOffers')->andReturn(collect([sdShopifyOffer()]));
    $source->shouldReceive('createShipment')->andReturnUsing(fn (): ShipResponse => sdShopifyShipResponse())->byDefault();

    app(CarrierRegistry::class)->registerInstance('Shopify', $source);

    return $source;
}

function sdShopifyRule(Package $package): void
{
    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => null,
        'any_service' => true,
    ]);
}

function sdBatchShip(Package $package): LabelBatchItem
{
    $batch = LabelBatch::factory()->create(['user_id' => User::factory()->create()->id]);
    $item = LabelBatchItem::factory()->create([
        'label_batch_id' => $batch->id,
        'shipment_id' => $package->shipment_id,
        'package_id' => $package->id,
        'status' => LabelBatchItemStatus::Pending,
    ]);

    (new GenerateLabelJob($item->id, 'pdf', null))->handle();

    return $item->fresh();
}

it('lists an Amazon offer into the EU for a client with no EU terms, labeled as decided by Amazon', function (): void {
    $package = sdAmazonPackage('DE');
    sdRegisterAmazonQuote();

    $options = app(PackageShippingWorkflow::class)->prepareRates($package->fresh());

    expect($options->rateOptions)->toHaveCount(1)
        ->and($options->rateOptions[0]['dutiesDecidedBy'])->toBe('Amazon');
});

it('does not label a domestic Amazon offer', function (): void {
    $package = sdAmazonPackage('US');
    sdRegisterAmazonQuote();

    $options = app(PackageShippingWorkflow::class)->prepareRates($package->fresh());

    expect($options->rateOptions)->toHaveCount(1)
        ->and($options->rateOptions[0])->not->toHaveKey('dutiesDecidedBy');
});

it('shows the label on the Ship page', function (): void {
    $package = sdAmazonPackage('DE');
    sdRegisterAmazonQuote();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('Duties: decided by Amazon');
});

it('does not auto ship an Amazon offer into a destination the source decides terms for, and names the destination', function (string $country, string $name): void {
    $package = sdAmazonPackage($country);
    $adapter = sdRegisterAmazonQuote();

    $result = sdAutoShip($this->user, $package);

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('Source Decides Duties Terms')
        ->and($result->message)->toContain('MockCarrier Ground')
        ->and($result->message)->toContain($name)
        ->and($result->message)->not->toContain('packer only')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $adapter->shouldNotHaveReceived('createShipment');
})->with([
    'EU' => ['DE', 'Germany'],
    'GB' => ['GB', 'United Kingdom'],
    'NO' => ['NO', 'Norway'],
    'AU' => ['AU', 'Australia'],
]);

it('says why on a batch ship item', function (): void {
    $package = sdAmazonPackage('DE');
    sdRegisterAmazonQuote();

    $item = sdBatchShip($package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toContain('decides the duties terms')
        ->and($item->error_message)->toContain('Germany');
});

it('auto ships a domestic Amazon offer as before', function (): void {
    $package = sdAmazonPackage('US');
    sdRegisterAmazonQuote();

    $result = sdAutoShip($this->user, $package);

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('marks a purchase redeemed from an Amazon Buy Shipping Offer as source-decided, though the rate rebuilt from the Offer has lost its observed service', function (): void {
    $package = sdAmazonPackage('DE');
    $offer = new ShippingOffer([
        'postage_source' => PostageSource::PostageDataSource,
        'purchase_context' => [AmazonBuyShippingService::CHANNEL_TYPE_KEY => AmazonChannelType::Amazon->value],
    ]);
    $rateFromOffer = new RateResponse('MockCarrier', 'GROUND', 'Ground', 4.00);

    $request = ShipRequest::fromPackageAndRate($package->fresh(), $rateFromOffer, 'pdf', null, $offer);

    expect($request->customsTerms->dutiesTermsOrigin)->toBe(CustomsTermsOrigin::SourceDecided)
        ->and(app(CustomsTermsSnapshot::class)->forRequest($request, new stdClass))->toMatchArray([
            'duties_terms' => null,
            'duties_terms_source' => 'source_decided',
            'registration' => null,
            'recipient_tax_id' => null,
            'export_itn' => null,
        ]);
});

it('treats Amazon Shipping quoted on an external channel as source-decided by its carrier, not by the Buy Shipping channel', function (): void {
    $offer = new ShippingOffer([
        'postage_source' => PostageSource::PostageDataSource,
        'purchase_context' => [AmazonBuyShippingService::CHANNEL_TYPE_KEY => AmazonChannelType::External->value],
    ]);
    $filter = app(DutiesTermsFilter::class);

    expect($filter->isSourceDecided(new RateResponse('MockCarrier', 'GROUND', 'Ground', 4.00), $offer))->toBeFalse()
        ->and($filter->isSourceDecided(new RateResponse(Carrier::AMAZON_SHIPPING, 'GROUND', 'Ground', 4.00), $offer))->toBeTrue();
});

it('holds a Shopify blind purchase into the EU that a rule selects, naming the destination and not the postage setting', function (): void {
    $package = sdShopifyPackage('DE');
    sdShopifyRule($package);
    $source = sdRegisterShopify();

    $result = sdAutoShip($this->user, $package);

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('Source Decides Duties Terms')
        ->and($result->message)->toContain('Shopify Shipping')
        ->and($result->message)->toContain('Germany')
        ->and($result->message)->not->toContain('packer only')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('holds a Shopify blind purchase into the EU that is the method\'s sole eligible choice', function (): void {
    $package = sdShopifyPackage('DE');
    $source = sdRegisterShopify();

    $result = sdAutoShip($this->user, $package);

    expect($result->title)->toBe('Source Decides Duties Terms')
        ->and($result->message)->toContain('Germany')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('holds the EU Shopify purchase in batch ship, with the destination as the reason', function (): void {
    $package = sdShopifyPackage('DE');
    sdShopifyRule($package);
    sdRegisterShopify();

    $item = sdBatchShip($package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toContain('decides the duties terms')
        ->and($item->error_message)->not->toContain('packer only');
});

it('reports the postage setting, not the destination, when the connection already sells to packers only', function (): void {
    $package = sdShopifyPackage('DE');
    setPostageSetting($package, PostageSetting::PackerOnly);
    sdRegisterShopify();

    $result = sdAutoShip($this->user, $package);

    expect($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('Connection Sells to Packers Only');
});

it('still buys the Shopify purchase to a domestic destination, by rule and as the sole choice', function (bool $byRule): void {
    $package = sdShopifyPackage('US');
    $source = sdRegisterShopify();
    $source->shouldReceive('createShipment')->once()->andReturn(sdShopifyShipResponse());

    if ($byRule) {
        sdShopifyRule($package);
    }

    $result = sdAutoShip($this->user, $package);

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
})->with(['sole choice' => false, 'rule' => true]);

it('lets a person buy the Shopify purchase into the EU from the Ship page, labeled and recorded as source_decided', function (): void {
    $package = sdShopifyPackage('DE');
    sdRegisterShopify();

    $options = app(PackageShippingWorkflow::class)->prepareRates($package->fresh());
    $result = app(PackageShippingWorkflow::class)->ship($package->fresh(), new PackageShippingRequest(
        blindOffer: sdShopifyOffer(),
        userId: $this->user->id,
        hasReportPrinter: true,
    ));

    $label = $package->fresh()->activeLabel()->firstOrFail();

    expect($result->success)->toBeTrue()
        ->and($options->blindPurchaseOffers[0]['dutiesDecidedBy'])->toBe('Shopify')
        ->and($label->customs_terms['duties_terms_source'])->toBe('source_decided')
        ->and($label->customs_terms['duties_terms'])->toBeNull()
        ->and($label->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and($label->postage_data_source_id)->not->toBeNull();
});

it('does not label a domestic Shopify purchase', function (): void {
    $package = sdShopifyPackage('US');
    sdRegisterShopify();

    $options = app(PackageShippingWorkflow::class)->prepareRates($package->fresh());

    expect($options->blindPurchaseOffers[0])->not->toHaveKey('dutiesDecidedBy');
});
