<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\LabelBatchItemStatus;
use App\Enums\PackageStatus;
use App\Enums\PostageSetting;
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
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| carrier-catalog-reset/13 — the method's allowance
|--------------------------------------------------------------------------
|
| What automation may buy is decided by the shipping method, narrowed by the
| connection's postage setting. A packer sees and may buy every offer; auto
| ship and batch ship buy only what the method allows. These tests exercise
| both halves against the same rate.
|
*/

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A rate as Amazon Buy Shipping quotes it: a real carrier of record, and the
 * source's own identity for the service riding along. Mapped when given the
 * catalog service it names.
 */
function discoveredRate(float $price, string $externalServiceId = 'USPS_GROUND_ADVANTAGE', ?int $carrierServiceId = null): RateResponse
{
    return new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: 'GROUND',
        serviceName: 'Ground',
        price: $price,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            externalCarrierId: 'USPS',
            externalServiceId: $externalServiceId,
        ),
        carrierServiceId: $carrierServiceId,
    );
}

/**
 * A package on a shipping method listing one carrier service, with no shipping
 * rule, so selection is rate shopping rather than a pre-selected rate. The
 * method has an `amazon` row saying this about unlisted services, when given.
 */
function packageForDiscoveredQuote(?UnlistedServices $amazon = UnlistedServices::None): Package
{
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $carrierService = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Ground',
        'service_code' => 'GROUND',
        'active' => true,
    ]);
    $shippingMethod = ShippingMethod::factory()->create(['name' => 'Standard']);
    $shippingMethod->carrierServices()->attach($carrierService->id);

    if ($amazon !== null) {
        ShippingMethodPostageSource::factory()->amazon()->create([
            'shipping_method_id' => $shippingMethod->id,
            'unlisted_services' => $amazon,
        ]);
    }

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

function listedServiceId(): int
{
    return CarrierService::where('service_code', 'GROUND')->value('id');
}

function directRate(float $price): RateResponse
{
    return new RateResponse('MockCarrier', 'GROUND', 'Ground', $price, carrierServiceId: listedServiceId());
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

function autoShipAs(User $user, Package $package): mixed
{
    return app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );
}

function batchShipPackage(Package $package): LabelBatchItem
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

it('lists an offer the method does not allow on the Ship page', function (): void {
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00)]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(1)
        ->and($options->rateOptions[0]['price'])->toBe(4.00)
        ->and($options->selectedRateIndex)->toBe(0);
});

it('buys an offer the method does not allow when a person deliberately chooses it', function (): void {
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

it('refuses to auto ship an unmapped Amazon offer under services on this method, and names the method', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00)]);

    $result = autoShipAs($user, $package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Not Allowed by Shipping Method')
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->message)->toContain('the shipping method "Standard"')
        ->and($result->message)->toContain('MockCarrier Ground (via Amazon Buy Shipping)')
        ->and($result->message)->not->toContain('Approv')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('auto ships an Amazon offer mapped to a service the method lists', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00, carrierServiceId: listedServiceId())]);

    $result = autoShipAs($user, $package);

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('auto ships an unmapped Amazon offer under any service', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote(UnlistedServices::Any);
    registerQuotingAdapter([discoveredRate(4.00, 'ONTRAC_MFN_GROUND')]);

    $result = autoShipAs($user, $package);

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('refuses every Amazon offer for a method with no amazon row', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote(amazon: null);
    registerQuotingAdapter([discoveredRate(4.00, carrierServiceId: listedServiceId())]);

    $result = autoShipAs($user, $package);

    expect($result->title)->toBe('Not Allowed by Shipping Method')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('never auto ships through Amazon Buy Shipping for a shipment with no method', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    $package->shipment->update(['shipping_method_id' => null]);
    registerQuotingAdapter([discoveredRate(4.00, carrierServiceId: listedServiceId())]);

    $result = autoShipAs($user, $package->fresh());

    expect($result->title)->toBe('Not Allowed by Shipping Method')
        ->and($result->message)->toContain('a shipment with no shipping method')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('passes over a cheaper offer the method does not allow for one it does', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00), directRate(9.00)], ShipResponse::success(
        trackingNumber: 'ALLOWED123',
        cost: 9.00,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));

    $result = autoShipAs($user, $package);

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->cost)->toEqual(9.00);
});

it('records the allowance refusal on a batch ship item rather than failing silently', function (): void {
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(4.00)]);

    $item = batchShipPackage($package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toContain('does not allow automation to buy');
});

it('refuses to auto ship only deactivated services, and says to reactivate rather than use the Ship page', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote(UnlistedServices::Any);
    // Amazon maps the offer to a service somebody deactivated; the method's
    // own service stays active, so the source is still asked.
    $deactivated = CarrierService::factory()->create([
        'carrier_id' => CarrierService::find(listedServiceId())->carrier_id,
        'service_code' => 'PRIORITY',
        'active' => false,
    ]);
    registerQuotingAdapter([discoveredRate(4.00, 'USPS_PTP_PRI', carrierServiceId: $deactivated->id)]);

    $result = autoShipAs($user, $package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Inactive Services Only')
        ->and($result->requiresAttendedSelection)->toBeFalse()
        ->and($result->message)->toContain('MockCarrier Ground')
        ->and($result->message)->toContain('Reactivate it')
        ->and($result->message)->not->toContain('Ship page')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('names a deactivated service beside an offer a packer can still choose', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    $deactivated = CarrierService::factory()->create([
        'carrier_id' => CarrierService::find(listedServiceId())->carrier_id,
        'service_code' => 'EXPRESS',
        'active' => false,
    ]);
    registerQuotingAdapter([
        discoveredRate(3.00, 'USPS_PTP_EXP', carrierServiceId: $deactivated->id),
        discoveredRate(4.00),
    ]);

    $result = autoShipAs($user, $package);

    expect($result->title)->toBe('Not Allowed by Shipping Method')
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->message)->toContain('Nothing buys MockCarrier Ground, because the service or its carrier is inactive.');
});

/**
 * A catalog service on the listed service's carrier that somebody deactivated,
 * which Amazon maps an offer to.
 */
function deactivatedService(): CarrierService
{
    return CarrierService::factory()->create([
        'carrier_id' => CarrierService::find(listedServiceId())->carrier_id,
        'name' => 'Priority',
        'service_code' => 'PRIORITY',
        'active' => false,
    ]);
}

it('shows an offer for a deactivated service on the Ship page, marked and never highlighted', function (): void {
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(3.00, 'USPS_PTP_PRI', carrierServiceId: deactivatedService()->id), directRate(9.00)]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(2)
        ->and($options->rateOptions[0]['price'])->toBe(3.00)
        ->and($options->rateOptions[0]['inactive'])->toBe('Priority is inactive.')
        ->and($options->rateOptions[1])->not->toHaveKey('inactive')
        ->and($options->selectedRateIndex)->toBe(1);
});

it('highlights nothing when every offer is for a deactivated service or carrier', function (): void {
    $package = packageForDiscoveredQuote();
    // Not the quoting carrier, which rating would then not ask at all.
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac', 'active' => false]);
    registerQuotingAdapter([
        discoveredRate(3.00, 'USPS_PTP_PRI', carrierServiceId: deactivatedService()->id),
        new RateResponse('OnTrac', 'ONTRAC_MFN_GROUND', 'Ground', 9.00, carrierId: $onTrac->id, observedService: new ObservedServiceIdentity('amazon', 'ONTRAC', 'ONTRAC_MFN_GROUND')),
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(2)
        ->and($options->rateOptions[1]['inactive'])->toBe('OnTrac is inactive.')
        ->and($options->selectedRateIndex)->toBeNull();
});

it('refuses a packer\'s purchase of an offer for a deactivated service', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    $deactivated = deactivatedService();
    $deactivated->update(['active' => true]);
    registerQuotingAdapter([discoveredRate(3.00, 'USPS_PTP_PRI', carrierServiceId: $deactivated->id)]);

    // Quoted while active, deactivated before the packer clicks Ship: the
    // page they are looking at still offers it.
    $options = app(PackageShippingWorkflow::class)->prepareRates($package);
    $deactivated->update(['active' => false]);

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(
        selectedRate: RateResponse::fromArray($options->rateOptions[0]),
        userId: $user->id,
    ));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Service Inactive')
        ->and($result->message)->toContain('Priority is inactive.')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('greys out an offer for a deactivated service on the Ship page and will not select it', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([discoveredRate(3.00, 'USPS_PTP_PRI', carrierServiceId: deactivatedService()->id), directRate(9.00)]);

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSet('selectedRateIndex', 1)
        ->assertSee('Inactive')
        ->assertSee('Priority is inactive. It cannot be bought until it is reactivated.')
        ->set('selectedRateIndex', 0)
        ->assertSet('selectedRateIndex', null);
});

/*
|--------------------------------------------------------------------------
| carrier-catalog-reset/10 — the connection's postage setting
|--------------------------------------------------------------------------
|
| An Amazon connection set to *packer only* keeps its Amazon orders' offers
| from automation whatever the method allows. *Packer and automation* leaves
| the decision to the method.
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

it('shows a packer-only connection\'s offer and refuses it unattended under any service, naming the setting', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote(UnlistedServices::Any);
    orderFromAmazonConnection($package, PostageSetting::PackerOnly);
    registerQuotingAdapter([discoveredRate(4.00)]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package->fresh());

    $result = autoShipAs($user, $package->fresh());

    expect($options->rateOptions)->toHaveCount(1)
        ->and($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->title)->toBe('Connection Sells to Packers Only')
        ->and($result->message)->toContain('MockCarrier Ground')
        ->and($result->message)->toContain('"Amazon US"')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('leaves a packer-and-automation connection\'s offer to the method', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    orderFromAmazonConnection($package, PostageSetting::PackerAndAutomation);
    registerQuotingAdapter([discoveredRate(4.00)]);

    $result = autoShipAs($user, $package->fresh());

    expect($result->title)->toBe('Not Allowed by Shipping Method')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('auto ships a packer-and-automation connection\'s offer the method allows', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    orderFromAmazonConnection($package, PostageSetting::PackerAndAutomation);
    registerQuotingAdapter([discoveredRate(4.00, carrierServiceId: listedServiceId())]);

    $result = autoShipAs($user, $package->fresh());

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('records the postage setting refusal on a batch ship item', function (): void {
    $package = packageForDiscoveredQuote(UnlistedServices::Any);
    orderFromAmazonConnection($package, PostageSetting::PackerOnly);
    registerQuotingAdapter([discoveredRate(4.00)]);

    $item = batchShipPackage($package);

    expect($item->status)->toBe(LabelBatchItemStatus::Failed)
        ->and($item->error_message)->toContain('sells postage to a packer only');
});

/*
|--------------------------------------------------------------------------
| carrier-catalog-reset/04 — content-restricted is never automated
|--------------------------------------------------------------------------
|
| Amazon's Bound Printed Matter is shown to a packer and never bought on
| nobody's behalf, because nothing in PolyBag vouches for the contents. No
| allowance can change that, so the refusal must not read as one.
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
            externalCarrierId: 'USPS',
            externalServiceId: 'USPS_PTP_BPM',
        ),
        contentRestricted: true,
    );
}

it('lists a content-restricted rate on the Ship page', function (): void {
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([contentRestrictedRate(3.00)]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(1)
        ->and($options->rateOptions[0]['serviceCode'])->toBe('USPS_PTP_BPM')
        ->and($options->rateOptions[0]['contentRestricted'])->toBeTrue();
});

it('never auto ships a content-restricted rate, even under any service, and names the restriction', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote(UnlistedServices::Any);
    registerQuotingAdapter([contentRestrictedRate(3.00)]);

    $result = autoShipAs($user, $package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Content-Restricted Rates Only')
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($result->message)->toContain('MockCarrier Bound Printed Matter')
        ->and($result->message)->toContain('restricted contents')
        // No allowance would release it, so the operator is not sent to one.
        ->and($result->message)->not->toContain('shipping method')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('passes over a cheaper content-restricted rate for one automation may buy', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote(UnlistedServices::Any);
    registerQuotingAdapter([contentRestrictedRate(3.00), directRate(9.00)], ShipResponse::success(
        trackingNumber: 'ALLOWED123',
        cost: 9.00,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));

    $result = autoShipAs($user, $package);

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->cost)->toEqual(9.00);
});

it('names a content-restricted rate beside one the method does not allow, apart from the allowance', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = packageForDiscoveredQuote();
    registerQuotingAdapter([contentRestrictedRate(3.00), discoveredRate(4.00)]);

    $result = autoShipAs($user, $package);

    expect($result->title)->toBe('Not Allowed by Shipping Method')
        ->and($result->message)->toContain('any service it was offered: MockCarrier Ground (via Amazon Buy Shipping).')
        ->and($result->message)->toContain('Automation also never buys MockCarrier Bound Printed Matter');
});
