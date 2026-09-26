<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\AmazonChannelType;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\PostageSetting;
use App\Enums\SourceEnvironment;
use App\Jobs\GenerateLabelJob;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\ServiceApproval;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;

/*
|--------------------------------------------------------------------------
| ADR-0003 decision 4 — discovered is not approved
|--------------------------------------------------------------------------
|
| The safety mechanism that makes discovery acceptable at all. An unapproved
| service is fully usable by a packer who sees the price and takes
| responsibility, and unreachable from every path that chooses on nobody's
| behalf. These tests exercise the two halves against the same rate.
|
*/

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A rate as a discovering source quotes it: a real carrier of record, and the
 * source's own identity for the service riding along.
 */
function discoveredRate(float $price, string $externalServiceId = 'USPS_GROUND_ADVANTAGE'): RateResponse
{
    return new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: 'GROUND',
        serviceName: 'Ground',
        price: $price,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            environment: SourceEnvironment::Production,
            channelType: AmazonChannelType::Amazon,
            externalCarrierId: 'USPS',
            externalServiceId: $externalServiceId,
        ),
    );
}

/**
 * A package on a shipping method with one carrier service and no shipping rule,
 * so selection is rate shopping rather than a pre-selected rate.
 */
function packageForDiscoveredQuote(): Package
{
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $carrierService = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Ground',
        'service_code' => 'GROUND',
        'active' => true,
    ]);
    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($carrierService->id);

    $product = Product::factory()->create(['weight' => 1.5]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $shippingMethod->id]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
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
 * @param  array<int, RateResponse>  $rates
 */
function registerQuotingAdapter(array $rates, ?ShipResponse $shipResponse = null): void
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect($rates));
    $adapter->shouldReceive('createShipment')->andReturn(
        $shipResponse ?? ShipResponse::success(
            trackingNumber: 'DISCOVERED123',
            cost: 4.00,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelData: base64_encode('label'),
        )
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

function approveForAutomation(Package $package, string $externalServiceId = 'USPS_GROUND_ADVANTAGE'): ServiceApproval
{
    return ServiceApproval::factory()->create([
        'source' => 'amazon',
        'environment' => SourceEnvironment::Production,
        'external_carrier_id' => 'USPS',
        'external_service_id' => $externalServiceId,
        'client_id' => $package->shipment->client_id,
    ]);
}

it('lists an unapproved discovered service on the Ship page', function (): void {
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00)]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(1)
        ->and($options->rateOptions[0]['price'])->toBe(4.00)
        ->and($options->selectedRateIndex)->toBe(0);
});

it('buys an unapproved discovered service when a person deliberately chooses it', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00)]);

    // Chosen off the list the Ship page shows, offer and all, the way a
    // person chooses: ship() refuses a rate that names no offer.
    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(
        selectedRate: RateResponse::fromArray($options->rateOptions[0]),
        userId: $user->id,
    ));

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('refuses to auto ship an unapproved discovered service, and says why', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00)]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('No Approved Rates')
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->message)->toContain('MockCarrier Ground (via amazon)')
        ->and($result->message)->toContain('Amazon Approvals')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('auto ships the same service once it is approved, with no code change', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    approveForAutomation($package);
    registerQuotingAdapter([discoveredRate(4.00)]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('passes over a cheaper unapproved service for one automation may buy', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([
        discoveredRate(4.00),
        new RateResponse('MockCarrier', 'GROUND', 'Ground', 9.00),
    ], ShipResponse::success(
        trackingNumber: 'APPROVED123',
        cost: 9.00,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->cost)->toEqual(9.00);
});

it('does not spend another client\'s approval on this package', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00)]);

    ServiceApproval::factory()->create([
        'source' => 'amazon',
        'environment' => SourceEnvironment::Production,
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
        'client_id' => Client::factory()->create()->id,
    ]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('No Approved Rates');
});

it('records the approval refusal on a batch ship item rather than failing silently', function (): void {
    $user = User::factory()->create();
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00)]);

    $batch = LabelBatch::factory()->create(['user_id' => $user->id]);
    $item = LabelBatchItem::factory()->create([
        'label_batch_id' => $batch->id,
        'shipment_id' => $package->shipment_id,
        'package_id' => $package->id,
        'status' => LabelBatchItemStatus::Pending,
    ]);

    (new GenerateLabelJob($item->id, 'pdf', null))->handle();

    expect($item->fresh()->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->fresh()->error_message)->toContain('approved for automated purchase');
});

/*
|--------------------------------------------------------------------------
| carrier-catalog-reset/10 — the connection's postage setting
|--------------------------------------------------------------------------
|
| An Amazon connection set to *packer only* keeps its Amazon orders' offers
| from automation even when approved. *Packer and automation* leaves the
| decision to the approvals, until `13`.
|
*/

function orderFromAmazonConnection(Package $package, PostageSetting $setting): DataSource
{
    $connection = DataSource::factory()->amazon()->sellingPostage($setting)->create(['name' => 'Amazon US']);
    $package->shipment->update(['data_source_id' => $connection->id]);

    // An Amazon order must have a due-by date to be checked for lateness,
    // which is not what these tests are about.
    $package->shipment->shippingMethod->update(['excludes_late_rates' => false]);

    return $connection;
}

it('shows a packer-only connection\'s approved offer and refuses it unattended, naming the setting', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    orderFromAmazonConnection($package, PostageSetting::PackerOnly);
    approveForAutomation($package);
    registerQuotingAdapter([discoveredRate(4.00)]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package->fresh());

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package->fresh(),
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($options->rateOptions)->toHaveCount(1)
        ->and($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('Connection Sells to Packers Only')
        ->and($result->message)->toContain('MockCarrier Ground')
        ->and($result->message)->toContain('"Amazon US"')
        ->and($result->message)->not->toContain('Amazon Approvals')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('leaves a packer-and-automation connection\'s unapproved offer to the approvals', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    orderFromAmazonConnection($package, PostageSetting::PackerAndAutomation);
    registerQuotingAdapter([discoveredRate(4.00)]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package->fresh(),
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->title)->toBe('No Approved Rates')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('auto ships a packer-and-automation connection\'s approved offer', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    orderFromAmazonConnection($package, PostageSetting::PackerAndAutomation);
    approveForAutomation($package);
    registerQuotingAdapter([discoveredRate(4.00)]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package->fresh(),
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('records the postage setting refusal on a batch ship item', function (): void {
    $user = User::factory()->create();
    $package = packageForDiscoveredQuote();
    orderFromAmazonConnection($package, PostageSetting::PackerOnly);
    approveForAutomation($package);
    registerQuotingAdapter([discoveredRate(4.00)]);

    $batch = LabelBatch::factory()->create(['user_id' => $user->id]);
    $item = LabelBatchItem::factory()->create([
        'label_batch_id' => $batch->id,
        'shipment_id' => $package->shipment_id,
        'package_id' => $package->id,
        'status' => LabelBatchItemStatus::Pending,
    ]);

    (new GenerateLabelJob($item->id, 'pdf', null))->handle();

    expect($item->fresh()->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->fresh()->error_message)->toContain('sells postage to a packer only');
});

/*
|--------------------------------------------------------------------------
| carrier-catalog-reset/04 — content-restricted is never automated
|--------------------------------------------------------------------------
|
| Amazon's Bound Printed Matter is shown to a packer and never bought on
| nobody's behalf, because nothing in PolyBag vouches for the contents. An
| approval cannot change that, so the refusal must not read as one.
|
*/

function contentRestrictedRate(float $price): RateResponse
{
    return new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: 'USPS_PTP_BPM',
        serviceName: 'Bound Printed Matter',
        price: $price,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            environment: SourceEnvironment::Production,
            channelType: AmazonChannelType::Amazon,
            externalCarrierId: 'USPS',
            externalServiceId: 'USPS_PTP_BPM',
        ),
        contentRestricted: true,
    );
}

function approveEverythingForAutomation(Package $package): ServiceApproval
{
    return ServiceApproval::factory()->create([
        'source' => 'amazon',
        'environment' => SourceEnvironment::Production,
        'external_carrier_id' => ServiceApproval::WILDCARD,
        'external_service_id' => ServiceApproval::WILDCARD,
        'client_id' => $package->shipment->client_id,
    ]);
}

it('lists a content-restricted rate on the Ship page', function (): void {
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([contentRestrictedRate(3.00)]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(1)
        ->and($options->rateOptions[0]['serviceCode'])->toBe('USPS_PTP_BPM')
        ->and($options->rateOptions[0]['contentRestricted'])->toBeTrue();
});

it('never auto ships a content-restricted rate, even under an approval of everything, and names the restriction', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    approveEverythingForAutomation($package);
    registerQuotingAdapter([contentRestrictedRate(3.00)]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Content-Restricted Rates Only')
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->message)->toContain('MockCarrier Bound Printed Matter')
        ->and($result->message)->toContain('restricted contents')
        // No approval would release it, so the operator is not sent to one.
        ->and($result->message)->not->toContain('Amazon Approvals')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('passes over a cheaper content-restricted rate for one automation may buy', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    approveEverythingForAutomation($package);
    registerQuotingAdapter([
        contentRestrictedRate(3.00),
        new RateResponse('MockCarrier', 'GROUND', 'Ground', 9.00),
    ], ShipResponse::success(
        trackingNumber: 'APPROVED123',
        cost: 9.00,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->cost)->toEqual(9.00);
});

it('names a content-restricted rate beside an unapproved one, apart from the approvals', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([contentRestrictedRate(3.00), discoveredRate(4.00)]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->title)->toBe('No Approved Rates')
        ->and($result->message)->toContain('approved for automated purchase: MockCarrier Ground (via amazon).')
        ->and($result->message)->toContain('Automation also never buys MockCarrier Bound Printed Matter');
});
