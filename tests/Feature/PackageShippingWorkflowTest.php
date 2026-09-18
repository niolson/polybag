<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CarrierPackaging;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\PackageStatus;
use App\Enums\ShippingRuleAction;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Http\Response;

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

it('prepares sorted rate options for a package', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->once()->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->once()->andReturnNull();
    $adapter->shouldReceive('getRates')->once()->andReturn(collect([
        new RateResponse('MockCarrier', 'EXPRESS', 'Express', 15.00, '1 day'),
        new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days'),
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
    // does not recognise is refused, never defaulted. The real USPS adapter
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

it('auto ships through a rule preselected rate', function (): void {
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
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

    // A rule's pre-selected rate never rate-shopped, so it carries no offer —
    // and the unattended path is the trusted side of the boundary ship()
    // enforces, so it buys anyway.
    expect($result->success)->toBeTrue()
        ->and($result->summaryMessage())->toContain('AUTO123')
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and(ShippingOffer::count())->toBe(0);
});

it('rate shops when the pre-selected service has no variant for the packaging', function (): void {
    // ADR-0005 decision 4: null from `resolvePreSelectedRate()` is "no
    // pre-selection", not a failure. The workflow says so in the log and buys
    // through rate shopping, where the same filter runs on real rates.
    $log = Log::spy();
    $this->actingAs($user = User::factory()->create());
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnNull();
    $adapter->shouldReceive('isConfigured')->once()->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->once()->andReturnNull();
    $adapter->shouldReceive('getRates')->once()->andReturn(collect([
        new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25, '3 days'),
    ]));
    $adapter->shouldReceive('createShipment')
        ->once()
        ->withArgs(fn ($shipRequest): bool => $shipRequest->selectedRate?->serviceCode === 'GROUND' && $shipRequest->selectedRate->price === 7.25)
        ->andReturn(ShipResponse::success(
            trackingNumber: 'SHOPPED123',
            cost: 7.25,
            carrier: 'MockCarrier',
            service: 'Ground',
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
        'Pre-selected service has no variant for this packaging; rate shopping instead',
        Mockery::on(fn (array $context): bool => $context['package_id'] === $package->id
            && $context['carrier'] === 'MockCarrier'
            && $context['service_code'] === 'GROUND'
            && array_key_exists('packaging', $context)),
    ]);
});

it('passes label format and dpi into auto ship requests', function (): void {
    $package = createWorkflowPackage();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
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

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
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

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('resolvePreSelectedRate')->once()->andReturnUsing(fn (RateResponse $rate): RateResponse => $rate);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::failure('Address validation failed')
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->autoShip($package, new PackageAutoShippingRequest);

    expect($result->success)->toBeFalse()
        ->and(Package::find($package->id))->toBeNull();
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

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('resolvePreSelectedRate')->andReturnUsing(fn ($rate) => $rate);
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
