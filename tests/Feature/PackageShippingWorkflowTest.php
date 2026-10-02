<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageDraftWorkflow;
use App\Contracts\PackageShippingWorkflow;
use App\Contracts\RecoversUnresolvedPurchase;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CarrierPackaging;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\PackageStatus;
use App\Enums\PickingStatus;
use App\Enums\PostageSource;
use App\Enums\Role;
use App\Enums\ShippingRuleAction;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Exceptions\PackageDraftIncompleteException;
use App\Http\Integrations\Ups\Requests\CreateShipment as UpsCreateShipment;
use App\Http\Integrations\Ups\Requests\Rate as UpsRate;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\ShippingRule;
use App\Models\SpecialService;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\PostageSources\OfferStore;
use App\Services\SettingsService;
use App\Services\ShippingRateService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

function createWorkflowPackage(): Package
{
    $boxSize = BoxSize::factory()->create();
    $product = Product::factory()->create(['weight' => 1.5]);
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $carrierService = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Ground',
        'service_code' => 'GROUND',
        'active' => true,
    ]);
    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($carrierService->id);
    ShippingRule::factory()->create([
        'shipping_method_id' => $shippingMethod->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $carrierService->id,
    ]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $shippingMethod->id]);

    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => $boxSize->id,
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
 * Make a mocked direct adapter quote the workflow package's Ground service,
 * the rate its *Use* rule selects among (`project-review/18`).
 */
function quotingWorkflowGround(MockInterface $adapter, float $price = 7.25): void
{
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse('MockCarrier', 'GROUND', 'Ground', $price, '3 days', carrierServiceId: CarrierService::where('service_code', 'GROUND')->value('id')),
    ]));
}

it('prepares sorted rate options for a package', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->once()->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->once()->andReturnNull();
    $adapter->shouldReceive('getRates')->once()->andReturn(collect([
        new RateResponse('MockCarrier', 'EXPRESS', 'Express', 15.00, '1 day'),
        new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days', carrierServiceId: CarrierService::where('service_code', 'GROUND')->value('id')),
    ]));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(2)
        ->and($options->rateOptions[0]['serviceCode'])->toBe('GROUND')
        ->and($options->selectedRateIndex)->toBe(0)
        ->and($options->rateOptionLabels[0])->toBe('[MockCarrier] Ground');
});

it('throws when a shipping method has no active carrier services', function (): void {
    $method = ShippingMethod::factory()->create();
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $package = Package::factory()->for($shipment)->create();

    expect(fn () => app(PackageShippingWorkflow::class)->prepareRates($package))
        ->toThrow(NoActiveCarrierServicesException::class);
});

it('returns a failure result and cleans up when auto ship rate lookup throws', function (): void {
    $method = ShippingMethod::factory()->create();
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $package = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);

    $result = app(PackageShippingWorkflow::class)->autoShip($package, new PackageAutoShippingRequest);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Auto Ship Error')
        ->and(Package::find($package->id))->toBeNull();
});

it('ships a package with the selected rate and marks it shipped', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(
            trackingNumber: 'TRACK123',
            cost: 7.25,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelData: base64_encode('label'),
        )
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate), userId: $user->id),
    );

    expect($result->success)->toBeTrue()
        ->and($result->printRequest)->not->toBeNull()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($package->fresh()->tracking_number)->toBe('TRACK123')
        ->and($package->fresh()->shipped_by_user_id)->toBe($user->id);
});

it('refuses a rate that requires carrier packaging the package is not in', function (): void {
    // ADR-0005 decision 4, the purchase re-check. Today it can never fail from
    // a real quote — every adapter classifies shipperPackaging() and every
    // Package is in the packer's own packaging — so it is proved with an
    // adapter that classifies the rate as needing a flat-rate box.
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $rate = new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: 'GROUND',
        serviceName: 'Ground',
        price: 7.25,
        packagingRequirement: PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox),
    );

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')
        ->once()
        ->andReturn(PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox));
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate), userId: $user->id),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Packaging Mismatch')
        ->and($result->message)->toContain('USPS Medium Flat Rate Box')
        ->and($result->leavePackageIntact)->toBeTrue()
        ->and($result->requiresRequote)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($package->fresh()->tracking_number)->toBeNull();
});

it('asks the adapter which packaging the rate needs, so the browser cannot switch the check off', function (): void {
    // A direct-carrier rate reaches the purchase rebuilt from Livewire state,
    // so its stamped requirement is whatever the browser sent. Here it says
    // shipper packaging — exactly what a tampered request would say — and the
    // adapter, classifying from the rate's own metadata, says otherwise.
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $browserRate = RateResponse::fromArray([
        'carrier' => 'MockCarrier',
        'serviceCode' => 'PRIORITY_MAIL',
        'serviceName' => 'Priority Mail',
        'price' => 9.65,
        'deliveryCommitment' => null,
        'deliveryDate' => null,
        'transitTime' => null,
        'metadata' => ['mailClass' => 'PRIORITY_MAIL', 'rateIndicator' => 'FB'],
        'packagingRequirement' => PackagingRequirement::shipperPackaging()->toArray(),
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')
        ->once()
        ->withArgs(fn (RateResponse $rate): bool => $rate->metadata['rateIndicator'] === 'FB')
        ->andReturn(PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox));
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $browserRate), userId: $user->id),
    );

    expect($browserRate->packagingRequirement->isShipperPackaging())->toBeTrue()
        ->and($result->success)->toBeFalse()
        ->and($result->title)->toBe('Packaging Mismatch')
        ->and($result->message)->toContain('USPS Medium Flat Rate Box')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('refuses a rate whose packaging the adapter cannot classify, rather than buying it as the shipper\'s own', function (): void {
    // The classifier invariant (ADR-0005 decision 3): an indicator the adapter
    // does not recognize is refused, never defaulted. The real USPS adapter
    // throws for it; the workflow turns that into a mismatch, not a 500.
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $rate = new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: 'PRIORITY_MAIL',
        serviceName: 'Priority Mail',
        price: 29.59,
        metadata: ['mailClass' => 'PRIORITY_MAIL', 'rateIndicator' => 'PM'],
    );

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')
        ->once()
        ->andThrow(new UnclassifiablePackagingException('MockCarrier', 'PM is not one PolyBag can place in a packaging.'));
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate), userId: $user->id),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Packaging Mismatch')
        ->and($result->message)->toContain('cannot match to a packaging')
        ->and($result->requiresRequote)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('returns a failure result when the carrier rejects the shipment', function (): void {
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::failure('Rate unavailable for this destination.')
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Shipping Error')
        ->and($result->message)->toBe('Rate unavailable for this destination.')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('reports a carrier timeout when shipping times out', function (): void {
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')
        ->once()
        ->andThrow(new RequestTimeOutException(Mockery::mock(Response::class), 'timed out'));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Carrier Timeout')
        ->and($result->message)->toContain('MockCarrier');
});

it('reports a carrier error when shipping raises a request exception', function (): void {
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')
        ->once()
        ->andThrow(new RequestException(Mockery::mock(Response::class), 'bad request'));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Carrier Error')
        ->and($result->message)->toContain('MockCarrier');
});

it('reports a state conflict when shipping raises a runtime exception', function (): void {
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')
        ->once()
        ->andThrow(new RuntimeException('Package was modified by another user.'));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Package State Changed')
        ->and($result->leavePackageIntact)->toBeTrue();
});

it('keeps a sold label recoverable when recording it fails, rather than buying another', function (): void {
    // The column a pending migration adds: the carrier sells the label, then
    // saving it fails. The offer must stay unresolved so the retry asks the
    // carrier for this label — it used to read as settled, and the retry
    // bought a second one.
    Schema::table('package_labels', fn (Blueprint $table) => $table->dropColumn('carrier_account_fingerprint'));
    $log = Log::spy();

    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');
    $sold = ShipResponse::success(
        trackingNumber: 'TRACK123',
        cost: 7.25,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    );

    $adapter = Mockery::mock(CarrierAdapterInterface::class, RecoversUnresolvedPurchase::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn($sold);
    $adapter->shouldReceive('recoverPurchase')->once()->andReturn($sold);
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $buyer = User::factory()->create(['role' => Role::User]);
    $manager = User::factory()->create(['role' => Role::Manager]);
    $otherShipper = User::factory()->create(['role' => Role::User]);

    $workflow = app(PackageShippingWorkflow::class);
    $first = $workflow->ship($package, new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate), userId: $buyer->id));

    $offer = ShippingOffer::whereNotNull('consumed_at')->sole();

    // In the bell, not only a toast: the buyer and whoever can act on it.
    expect($buyer->notifications()->sole()->data['title'])->toContain('Label bought but not recorded')
        ->and($buyer->notifications()->sole()->data['body'])->toContain('MockCarrier sold label TRACK123')
        ->and($manager->notifications()->count())->toBe(1)
        ->and($otherShipper->notifications()->count())->toBe(0);

    expect($first->success)->toBeFalse()
        ->and($first->title)->toBe('Label Bought but Not Recorded')
        ->and($first->message)->toContain('MockCarrier sold label TRACK123')
        ->and($first->leavePackageIntact)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($package->labels()->count())->toBe(0)
        ->and($offer->isAwaitingPurchaseConfirmation())->toBeTrue()
        ->and($offer->purchase_context[OfferStore::REPORTED_TRACKING_NUMBER] ?? null)->toBe('TRACK123');

    $log->shouldHaveReceived('error', [
        'Bought a label but could not record it',
        Mockery::on(fn (array $context): bool => $context['tracking_number'] === 'TRACK123'
            && $context['seller'] === 'MockCarrier'
            && $context['recoverable'] === true),
    ]);

    Schema::table('package_labels', fn (Blueprint $table) => $table->string('carrier_account_fingerprint', 64)->nullable());

    $second = $workflow->ship($package->fresh(), new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)));

    expect($second->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($package->fresh()->tracking_number)->toBe('TRACK123')
        ->and($offer->fresh()->purchase_reference)->toBe('TRACK123');
});

it('reports a recorded label as shipped when post-ship work fails after the commit', function (): void {
    // PackageShipped's listeners run once the label has committed. One of them
    // throwing used to be reported as "label not recorded", with no print.
    $log = Log::spy();
    $package = createWorkflowPackage();
    $buyer = User::factory()->create(['role' => Role::User]);
    Schema::drop('audit_logs');
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn(ShipResponse::success(
        trackingNumber: 'TRACK123',
        cost: 7.25,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate), userId: $buyer->id),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($package->fresh()->tracking_number)->toBe('TRACK123')
        ->and($buyer->notifications()->count())->toBe(0);

    $log->shouldHaveReceived('warning', [
        'Recorded a label but post-ship work failed',
        Mockery::on(fn (array $context): bool => $context['tracking_number'] === 'TRACK123'),
    ]);
    $log->shouldNotHaveReceived('error', ['Bought a label but could not record it', Mockery::any()]);
});

it('counts an auto-shipped label as bought when post-ship work fails after the commit', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    Schema::drop('audit_logs');

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    quotingWorkflowGround($adapter);
    $adapter->shouldReceive('createShipment')->once()->andReturn(ShipResponse::success(
        trackingNumber: 'AUTO123',
        cost: 7.25,
        carrier: 'MockCarrier',
        service: 'Ground',
        labelData: base64_encode('label'),
    ));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id),
    );

    expect($result->success)->toBeTrue()
        ->and($result->summaryMessage())->toContain('AUTO123')
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('asks again for a label the source confirmed but PolyBag never recorded, rather than buying another', function (): void {
    // Amazon stamps the offer the moment it confirms, outside the transaction
    // that saves the Label. That stamp survives a failed save, so the offer
    // reads as settled — and nothing would ask Amazon before buying again.
    Schema::table('package_labels', fn (Blueprint $table) => $table->dropColumn('carrier_account_fingerprint'));

    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');
    $sold = ShipResponse::success(trackingNumber: 'TRACK123', cost: 7.25, carrier: 'MockCarrier', service: 'Ground', labelData: base64_encode('label'));

    $adapter = Mockery::mock(CarrierAdapterInterface::class, RecoversUnresolvedPurchase::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturnUsing(function (ShipRequest $request) use ($sold): ShipResponse {
        app(OfferStore::class)->recordPurchase($request->offer, 'SOURCE-SHIPMENT-1');

        return $sold;
    });
    $adapter->shouldReceive('recoverPurchase')->once()->andReturn($sold);
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $workflow = app(PackageShippingWorkflow::class);
    $first = $workflow->ship($package, new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)));

    $offer = ShippingOffer::whereNotNull('consumed_at')->sole();
    $offers = app(OfferStore::class);

    expect($first->title)->toBe('Label Bought but Not Recorded')
        ->and($offer->purchase_reference)->toBe('SOURCE-SHIPMENT-1')
        ->and($offers->awaitingPurchaseConfirmation($package))->toBeEmpty()
        ->and($offers->boughtButUnrecorded($package)->modelKeys())->toBe([$offer->id])
        ->and($offers->hasUnresolvedPurchase($package))->toBeTrue();

    Schema::table('package_labels', fn (Blueprint $table) => $table->string('carrier_account_fingerprint', 64)->nullable());

    $second = $workflow->ship($package->fresh(), new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)));

    expect($second->success)->toBeTrue()
        ->and($package->fresh()->tracking_number)->toBe('TRACK123')
        ->and($offers->boughtButUnrecorded($package))->toBeEmpty();
});

it('names channel postage by the connection that sold it, and gives no retry advice a source cannot honor', function (): void {
    // USPS postage bought through Amazon is Amazon's to void, not USPS's; and a
    // source that cannot be asked again must not be retried.
    Schema::table('package_labels', fn (Blueprint $table) => $table->dropColumn('carrier_account_fingerprint'));

    $connection = DataSource::factory()->amazon()->create(['name' => 'Acme Amazon']);
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn(new ShipResponse(
        success: true,
        trackingNumber: 'TRACK123',
        cost: 7.25,
        carrier: 'USPS',
        service: 'Ground Advantage',
        labelData: base64_encode('label'),
        postageSource: PostageSource::PostageDataSource,
        postageDataSourceId: $connection->id,
    ));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)),
    );

    expect($result->title)->toBe('Label Bought but Not Recorded')
        ->and($result->message)->toStartWith('Amazon (connection "Acme Amazon") sold label TRACK123')
        ->and($result->message)->toContain('Do not buy another label')
        ->and($result->message)->not->toContain('Try again');
});

it('logs a database error before the purchase instead of reporting it as a race', function (): void {
    $log = Log::spy();
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')
        ->once()
        ->andThrow(new QueryException('sqlite', 'insert into rate_quotes', [], new PDOException('no such column')));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Shipping Error')
        ->and($result->message)->not->toContain('insert into')
        ->and($result->leavePackageIntact)->toBeTrue();

    $log->shouldHaveReceived('error', [
        'Database error while buying postage',
        Mockery::on(fn (array $context): bool => $context['package_id'] === $package->id),
    ]);
});

it('reports a generic error when shipping raises an unexpected exception', function (): void {
    $package = createWorkflowPackage();
    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days');

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')
        ->once()
        ->andThrow(new Exception('boom'));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: quotedDirectly($package, $rate)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Shipping Error')
        ->and($result->message)->toBe('An unexpected error occurred. Please try again.');
});

it('auto ships through a rule selected rate', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    quotingWorkflowGround($adapter);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(
            trackingNumber: 'AUTO123',
            cost: 7.25,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelData: base64_encode('label'),
        )
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    // The rule selects among quoted rates, so what it buys carries the offer
    // its quote issued: the recovery record every purchase needs.
    $offer = ShippingOffer::sole();

    expect($result->success)->toBeTrue()
        ->and($result->summaryMessage())->toContain('AUTO123')
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($offer->postage_source)->toBe(PostageSource::CarrierAccount)
        ->and($offer->purchase_reference)->toBe('AUTO123')
        ->and($offer->quote_fingerprint)->not->toBeNull()
        ->and($offer->expires_at)->not->toBeNull();
});

it('refuses an auto-ship rate carrying no offer before any adapter is called', function (): void {
    // Automation's rates are built server-side, but the offer is also the
    // claim and the record a timed-out purchase is recovered from. Bought
    // without one, a timeout can buy twice (`project-review/02`), so the
    // purchase demands it whichever entry point sent the rate (`/05`).
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();

    $this->partialMock(ShippingRateService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getShippingRates')->andReturn(collect([
            new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days', carrierServiceId: CarrierService::where('service_code', 'GROUND')->value('id')),
        ]));
        $mock->shouldReceive('soleBlindPurchaseOfferForAutomation')->andReturnNull();
    });

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->never()->andReturn(ShipResponse::failure('unexpected'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Rate Unavailable')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('records the account a rule-selected purchase will be bought on', function (): void {
    // A rule names a service, not an account. The offer records the account
    // the rate was quoted on, so the account check at purchase runs and
    // recovery asks the account the label was bought on. The real adapter,
    // so the account is the one UPS quoting resolves, not a mock's.
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $account = createUpsAccount();
    $upsGround = CarrierService::factory()->create([
        'carrier_id' => $account->carrier_id,
        'name' => 'UPS Ground',
        'service_code' => '03',
        'active' => true,
    ]);
    $package->shipment->shippingMethod->carrierServices()->detach();
    $package->shipment->shippingMethod->carrierServices()->attach($upsGround->id);
    ShippingRule::query()->update(['carrier_service_id' => $upsGround->id]);

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        UpsRate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => [[
            'Service' => ['Code' => '03'],
            'TotalCharges' => ['MonetaryValue' => '7.25'],
        ]]]]),
        UpsCreateShipment::class => MockResponse::make(['ShipmentResponse' => ['ShipmentResults' => [
            'ShipmentIdentificationNumber' => '1Z9999999999999999',
            'ShipmentCharges' => ['TotalCharges' => ['MonetaryValue' => '7.25']],
            'PackageResults' => [
                'TrackingNumber' => '1Z9999999999999999',
                'ShippingLabel' => ['GraphicImage' => 'R0lGODlhAQABAAAAACw='],
            ],
        ]]]),
    ]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    $offer = ShippingOffer::whereNotNull('consumed_at')->sole();

    expect($result->success)->toBeTrue()
        ->and($offer->carrier_service_id)->toBe($upsGround->id)
        ->and($offer->carrier_account_id)->toBe($account->id)
        ->and($offer->carrier_account_fingerprint)->toBe($account->fingerprint());
});

it('still asks for a declared value, rather than failing, on a rule-selected rate that needs one', function (): void {
    // Rating the package applies every declared-value code on the method and
    // throws before anything is offered or bought. A rule's choice is rated
    // too (`project-review/18`), so it answers as the Ship page does, not
    // with a generic "Auto Ship Error".
    $package = createWorkflowPackage();
    $declaredValue = SpecialService::create([
        'code' => 'declared_value',
        'name' => 'Declared Value',
        'scope' => 'package',
        'category' => 'insurance',
        'requires_value' => true,
        'active' => true,
    ]);
    $package->shipment->shippingMethod->specialServices()->attach($declaredValue->id, ['mode' => 'required']);
    $package->shipment->update(['value' => null]);
    $package->shipment->shipmentItems()->update(['value' => null]);

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    quotingWorkflowGround($adapter);
    $adapter->shouldNotReceive('createShipment');

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip($package->fresh(), new PackageAutoShippingRequest(cleanupOnFailure: false));

    expect($result->title)->toBe('Declared Value Required');
});

it('does not buy again after a rule-selected purchase went unanswered', function (): void {
    // project-review/02: with no offer, a timeout left nothing unresolved and
    // the next unattended attempt bought a second label. A carrier that can be
    // asked (USPS, UPS) and has no answer yet keeps the package blocked.
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(DirectCarrierAdapter::class, RecoversUnresolvedPurchase::class);
    $adapter->shouldReceive('recoverPurchase')->once()->andReturnNull();
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    quotingWorkflowGround($adapter);
    $calls = 0;
    $adapter->shouldReceive('createShipment')->andReturnUsing(function () use (&$calls): never {
        $calls++;

        throw new RequestTimeOutException(Mockery::mock(Response::class), 'timed out');
    });

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $workflow = app(PackageShippingWorkflow::class);
    $first = $workflow->autoShip($package, new PackageAutoShippingRequest(cleanupOnFailure: false));
    $second = $workflow->autoShip($package->fresh(), new PackageAutoShippingRequest(cleanupOnFailure: false));

    expect($first->title)->toBe('Carrier Timeout')
        ->and(ShippingOffer::whereNotNull('consumed_at')->sole()->isAwaitingPurchaseConfirmation())->toBeTrue()
        ->and($second->title)->toBe('Earlier Purchase Unresolved')
        ->and($calls)->toBe(1);
});

it('rate shops when nothing quoted is in the rule\'s scope', function (): void {
    // A *Direct* rule names a service no rate was quoted for, as when no
    // variant of it fits the packaging (ADR-0005 decision 4). Not a failure:
    // the workflow says so in the log and buys through rate shopping, with
    // the rule's exclusions still applied.
    $log = Log::spy();
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $express = CarrierService::factory()->create([
        'carrier_id' => Carrier::where('name', 'MockCarrier')->value('id'),
        'name' => 'Express',
        'service_code' => 'EXPRESS',
        'active' => true,
    ]);
    $package->shipment->shippingMethod->carrierServices()->attach($express->id);

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->once()->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->once()->andReturnNull();
    $adapter->shouldReceive('getRates')->once()->andReturn(collect([
        new RateResponse('MockCarrier', 'EXPRESS', 'Express', 12.50, '1 day', carrierServiceId: $express->id),
    ]));
    $adapter->shouldReceive('createShipment')
        ->once()
        ->withArgs(fn ($shipRequest): bool => $shipRequest->selectedRate?->serviceCode === 'EXPRESS' && $shipRequest->selectedRate->price === 12.50)
        ->andReturn(ShipResponse::success(
            trackingNumber: 'SHOPPED123',
            cost: 12.50,
            carrier: 'MockCarrier',
            service: 'Express',
            labelData: base64_encode('label'),
        ));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($result->summaryMessage())->toContain('SHOPPED123')
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);

    $log->shouldHaveReceived('info', [
        'A shipping rule names a service no source quoted, or an Exclude rule removed, for this package; rate shopping instead',
        Mockery::on(fn (array $context): bool => $context['package_id'] === $package->id
            && $context['carrier_service_id'] === CarrierService::where('service_code', 'GROUND')->value('id')),
    ]);
});

it('passes label format and dpi into auto ship requests', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    quotingWorkflowGround($adapter);
    $adapter->shouldReceive('createShipment')
        ->once()
        ->withArgs(fn ($shipRequest): bool => $shipRequest->labelFormat === 'zpl' && $shipRequest->labelDpi === 203)
        ->andReturn(ShipResponse::success(
            trackingNumber: 'ZPL123',
            cost: 5.00,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelFormat: 'zpl',
            labelDpi: 203,
        ));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(labelFormat: 'zpl', labelDpi: 203),
    );

    expect($result->success)->toBeTrue()
        ->and($result->response->labelFormat)->toBe('zpl')
        ->and($result->response->labelDpi)->toBe(203);
});

it('can preserve an unshipped package when auto ship fails', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    quotingWorkflowGround($adapter);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::failure('Address validation failed')
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Address validation failed')
        ->and($package->fresh())->not->toBeNull()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('cleans up an unshipped package when auto ship fails by default', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    quotingWorkflowGround($adapter);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::failure('Address validation failed')
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip($package, new PackageAutoShippingRequest);

    expect($result->success)->toBeFalse()
        ->and(Package::find($package->id))->toBeNull();
});

it('keeps a package whose purchase went unanswered, even when cleanup was asked for', function (): void {
    // project-review/01: a timeout came back as a plain failure, the default
    // cleanup deleted the package, and the cascade took the unresolved offer —
    // the only record that a label may exist.
    $package = createWorkflowPackage();
    ShippingRule::query()->delete();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days', carrierServiceId: CarrierService::where('service_code', 'GROUND')->value('id')),
    ]));
    $adapter->shouldReceive('createShipment')->once()->andThrow(new RequestTimeOutException(Mockery::mock(Response::class), 'timed out'));

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip($package, new PackageAutoShippingRequest);

    expect($result->title)->toBe('Carrier Timeout')
        ->and(Package::find($package->id))->not->toBeNull()
        ->and(ShippingOffer::whereNotNull('consumed_at')->sole()->isAwaitingPurchaseConfirmation())->toBeTrue();
});

it('prompts for a customs weight override when a military destination is overweight', function (): void {
    // Military addresses are domestic but customs-declared, so they reach the
    // carrier with customs items. Gating the override on the country alone let
    // those items through unreconciled, and USPS rejected the label: "total
    // weight of all of the content items cannot be more than the total weight".
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $package->update(['weight' => 0.7]);
    $package->shipment->update([
        'city' => 'FPO',
        'state_or_province' => 'AE',
        'postal_code' => '09532',
        'country' => 'US',
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    // A military lane clears customs, so the report printer gate asks the
    // seller first. Fused is what lets the test reach the weight prompt.
    $adapter->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::FusedIntoLabel);
    // A concrete return value, even though the call is not expected: Mockery
    // cannot synthesize one for the readonly ShipResponse, and a regression
    // should fail this test rather than fatal out of the whole suite.
    $adapter->shouldReceive('createShipment')->never()->andReturn(
        ShipResponse::success(
            trackingNumber: 'UNEXPECTED',
            cost: 7.25,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelData: base64_encode('label'),
        )
    );
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(
            selectedRate: quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days')),
            userId: $user->id,
        ),
    );

    expect($result->requiresCustomsWeightOverride)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('scales customs weights for a military destination once the override is confirmed', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $package->update(['weight' => 0.7]);
    $package->shipment->update([
        'city' => 'FPO',
        'state_or_province' => 'AE',
        'postal_code' => '09532',
        'country' => 'US',
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    // A military lane clears customs, so the report printer gate asks the
    // seller first. Fused is what lets the test reach the weight prompt.
    $adapter->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::FusedIntoLabel);
    $adapter->shouldReceive('createShipment')->once()->andReturnUsing(
        function ($shipRequest): ShipResponse {
            $total = collect($shipRequest->customsItems)
                ->sum(fn ($item): float => $item->weight * $item->quantity);

            expect($total)->toBeLessThanOrEqual($shipRequest->packageData->weight);

            return ShipResponse::success(
                trackingNumber: 'TRACK123',
                cost: 7.25,
                carrier: 'MockCarrier',
                service: 'Ground',
                labelData: base64_encode('label'),
            );
        }
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(
            selectedRate: quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days')),
            userId: $user->id,
            overrideCustomsWeights: true,
        ),
    );

    expect($result->success)->toBeTrue();
});

it('does not prompt for a customs override on an ordinary domestic destination', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $package->update(['weight' => 0.7]);
    $package->shipment->update([
        'city' => 'Los Angeles',
        'state_or_province' => 'CA',
        'postal_code' => '90210',
        'country' => 'US',
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(
            trackingNumber: 'TRACK123',
            cost: 7.25,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelData: base64_encode('label'),
        )
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(
            selectedRate: quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days')),
            userId: $user->id,
        ),
    );

    expect($result->success)->toBeTrue();
});

/**
 * A lane that clears customs, for the report printer gate. Canada rather than
 * a military address so the customs weight prompt stays out of the way: the
 * package weighs more than its one item.
 */
function sendWorkflowPackageAbroad(Package $package): void
{
    $package->shipment->update([
        'address1' => '100 Queen St W',
        'city' => 'Toronto',
        'state_or_province' => 'ON',
        'postal_code' => 'M5H 2N2',
        'country' => 'CA',
    ]);
}

it('refuses to buy from a seller that returns a separate customs document when no report printer is configured', function (): void {
    // shopify-shipping-carrier/07 constraint 3: the document would come back
    // and have nowhere to print, so nothing is bought.
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    sendWorkflowPackageAbroad($package);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('getCarrierName')->andReturn('MockCarrier');
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('customsDocumentDelivery')->once()->andReturn(CustomsDocumentDelivery::Separate);
    $adapter->shouldReceive('createShipment')->never()->andReturn(
        ShipResponse::success(trackingNumber: 'UNEXPECTED', cost: 7.25, carrier: 'MockCarrier', service: 'Ground', labelData: base64_encode('label')),
    );
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(
            selectedRate: quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days')),
            userId: $user->id,
            hasReportPrinter: false,
        ),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Document Printer Required')
        ->and($result->message)->toContain('Device Settings')
        ->and($result->leavePackageIntact)->toBeTrue()
        ->and($result->requiresRequote)->toBeFalse()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('buys from a seller that fuses the customs form into the label without a report printer', function (): void {
    // The over-block shopify-shipping-carrier/23 exists to prevent: USPS's
    // CP72 is three plies inside the label and prints on the thermal path, so
    // a workstation with only a label printer keeps its international labels.
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    sendWorkflowPackageAbroad($package);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('customsDocumentDelivery')->once()->andReturn(CustomsDocumentDelivery::FusedIntoLabel);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(trackingNumber: 'FUSED123', cost: 7.25, carrier: 'MockCarrier', service: 'Ground', labelData: base64_encode('label')),
    );
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(
            selectedRate: quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days')),
            userId: $user->id,
            hasReportPrinter: false,
        ),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->tracking_number)->toBe('FUSED123');
});

it('buys from a seller that returns a separate customs document once a report printer is configured', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    sendWorkflowPackageAbroad($package);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    // With a report printer every answer prints, so the seller is not asked.
    $adapter->shouldNotReceive('customsDocumentDelivery');
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(trackingNumber: 'SEPARATE123', cost: 7.25, carrier: 'MockCarrier', service: 'Ground', labelData: base64_encode('label')),
    );
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(
            selectedRate: quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days')),
            userId: $user->id,
            hasReportPrinter: true,
        ),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->tracking_number)->toBe('SEPARATE123');
});

it('does not ask the seller about customs documents on a domestic lane', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldNotReceive('customsDocumentDelivery');
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(trackingNumber: 'DOMESTIC123', cost: 7.25, carrier: 'MockCarrier', service: 'Ground', labelData: base64_encode('label')),
    );
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(
            selectedRate: quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days')),
            userId: $user->id,
            hasReportPrinter: false,
        ),
    );

    expect($result->success)->toBeTrue();
});

it('carries the report printer flag into an unattended purchase', function (): void {
    // Batch ship and auto ship arrive through autoShip(); the workstation's
    // answer has to survive the hop into the attended request or every batch
    // would be refused as if no report printer existed.
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    sendWorkflowPackageAbroad($package);

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    quotingWorkflowGround($adapter);
    $adapter->shouldNotReceive('customsDocumentDelivery');
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(trackingNumber: 'AUTO123', cost: 7.25, carrier: 'MockCarrier', service: 'Ground', labelData: base64_encode('label')),
    );
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package->fresh(),
        new PackageAutoShippingRequest(userId: $user->id, hasReportPrinter: true),
    );

    expect($result->success)->toBeTrue();
});

/**
 * A carrier that must not be asked for anything: the package is refused first.
 */
function refusingAdapter(): void
{
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

it('refuses to buy for a package with no measurements, as when the Pack page was left before a box was scanned', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $rate = quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days'));
    $package->update(['weight' => 0, 'height' => null, 'width' => null, 'length' => null]);
    refusingAdapter();

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $rate, userId: $user->id));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Not Ready')
        ->and($result->message)->toBe('Package draft is missing valid measurements.')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('refuses to buy for a package whose items are not all packed', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $rate = quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days'));
    $package->packageItems()->delete();
    refusingAdapter();

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $rate, userId: $user->id));

    expect($result->title)->toBe('Not Ready')
        ->and($result->message)->toBe('Not all shipment items are packed.');
});

it('buys for a partly packed package when packing validation is off, but still wants measurements', function (): void {
    app(SettingsService::class)->set('packing_validation_enabled', false);
    $package = createWorkflowPackage();
    $package->packageItems()->delete();

    expect(app(PackageDraftWorkflow::class)->assertPackageReadyToShip($package)->package->id)->toBe($package->id);

    $package->update(['weight' => 0]);

    expect(fn () => app(PackageDraftWorkflow::class)->assertPackageReadyToShip($package))
        ->toThrow(PackageDraftIncompleteException::class, 'Package draft is missing valid measurements.');
});

it('refuses to buy for a shipment that must be picked first', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();
    $rate = quotedDirectly($package, new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days'));
    app(SettingsService::class)->set('picking_enabled', true);
    app(SettingsService::class)->set('require_picking_before_shipping', true);
    $package->shipment->update(['picking_status' => PickingStatus::Pending]);
    refusingAdapter();

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $rate, userId: $user->id));

    expect($result->title)->toBe('Not Ready')
        ->and($result->message)->toContain('must be picked');
});

it('refuses an unready package on auto ship without quoting it, and keeps it for the packer to finish', function (): void {
    $package = createWorkflowPackage();
    $package->update(['weight' => 0]);
    refusingAdapter();

    $result = app(PackageShippingWorkflow::class)->autoShip($package, new PackageAutoShippingRequest);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Not Ready')
        ->and(Package::find($package->id))->not->toBeNull();
});
