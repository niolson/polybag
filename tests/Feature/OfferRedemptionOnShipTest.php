<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Models\Package;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingOffer;
use App\Services\Carriers\CarrierRegistry;
use App\Services\SettingsService;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Http\Response;

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A rate as it comes back from Livewire: the offer identifier and a
 * description, and nothing that could buy a label on its own.
 */
function rateForOffer(ShippingOffer $offer): RateResponse
{
    return RateResponse::fromArray((new RateResponse(
        carrier: 'MockCarrier',
        serviceCode: 'GROUND',
        serviceName: 'Ground',
        price: 7.25,
        offerId: $offer->public_id,
    ))->toArray());
}

/**
 * A package whose customs items outweigh the parcel, so `ship()` stops for the
 * operator to confirm. Military addresses are domestic but customs-declared,
 * which is the cheapest way to reach that branch.
 */
function internationalPackageNeedingOverride(): Package
{
    $product = Product::factory()->create(['weight' => 1.5]);
    $shipment = Shipment::factory()->create([
        'city' => 'FPO',
        'state_or_province' => 'AE',
        'postal_code' => '09532',
        'country' => 'US',
    ]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'weight' => 0.7,
        'status' => PackageStatus::Unshipped,
    ]);

    $package->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package;
}

function mockShippingAdapter(?ShipResponse $response = null): void
{
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('createShipment')->andReturn(
        $response ?? ShipResponse::success(
            trackingNumber: 'TRACK123',
            cost: 7.25,
            carrier: 'MockCarrier',
            service: 'Ground',
            labelData: base64_encode('label'),
        )
    );

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

it('spends the offer and ties it to the purchase', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $offer = ShippingOffer::factory()->for($package)->create(['carrier' => 'MockCarrier', 'postage_source' => PostageSource::CarrierAccount]);
    mockShippingAdapter();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: rateForOffer($offer)),
    );

    expect($result->success)->toBeTrue()
        ->and($offer->fresh()->consumed_at)->not->toBeNull()
        ->and($offer->fresh()->purchase_reference)->toBe('TRACK123')
        ->and($offer->fresh()->isAwaitingPurchaseConfirmation())->toBeFalse();
});

it('refuses to buy against an expired offer and never reaches the carrier', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $offer = ShippingOffer::factory()->expired()->for($package)->create(['carrier' => 'MockCarrier', 'postage_source' => PostageSource::CarrierAccount]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: rateForOffer($offer)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Rate Expired')
        ->and($result->message)->toContain('Get rates again')
        ->and($result->leavePackageIntact)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('refuses a second purchase against an offer already spent', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $offer = ShippingOffer::factory()->for($package)->create(['carrier' => 'MockCarrier', 'postage_source' => PostageSource::CarrierAccount]);
    mockShippingAdapter();

    $rate = rateForOffer($offer);
    $workflow = app(PackageShippingWorkflow::class);

    $workflow->ship($package, new PackageShippingRequest(selectedRate: $rate));

    // A shipped package is refused before the offer is even looked at, so
    // this is the label voided in between: the package can be bought for
    // again, but not with the offer that already paid for it once.
    $package->fresh()->update(['status' => PackageStatus::Unshipped]);
    $second = $workflow->ship($package->fresh(), new PackageShippingRequest(selectedRate: $rate));

    expect($second->success)->toBeFalse()
        ->and($second->title)->toBe('Rate Already Used');
});

it('settles a declined purchase rather than leaving the package jammed', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $offer = ShippingOffer::factory()->for($package)->create(['carrier' => 'MockCarrier', 'postage_source' => PostageSource::CarrierAccount]);
    mockShippingAdapter(ShipResponse::failure('The carrier rejected the shipment.'));

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: rateForOffer($offer)),
    );

    // The offer stays spent — it is never returned to the pool — but the source
    // answered, so nothing was bought and the package is free to be quoted
    // again.
    expect($result->success)->toBeFalse()
        ->and($offer->fresh()->consumed_at)->not->toBeNull()
        ->and($offer->fresh()->isAwaitingPurchaseConfirmation())->toBeFalse()
        ->and($offer->fresh()->purchase_failure_reason)->toBe('The carrier rejected the shipment.');
});

it('leaves an offer unresolved when the carrier never answers', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $offer = ShippingOffer::factory()->for($package)->create(['carrier' => 'MockCarrier', 'postage_source' => PostageSource::CarrierAccount]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('createShipment')
        ->andThrow(new RequestTimeOutException(Mockery::mock(Response::class), 'timed out'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: rateForOffer($offer)),
    );

    // A timeout is not an answer. The label may exist, so the offer stays
    // ambiguous and the package is blocked until someone finds out.
    expect($offer->fresh()->isAwaitingPurchaseConfirmation())->toBeTrue();
});

it('refuses to buy while an earlier purchase is unaccounted for', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    ShippingOffer::factory()->awaitingConfirmation()->for($package)->create();

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    // Not only the offer that stalled: nothing may be bought for this package
    // at all, on any source, until the outstanding purchase is settled.
    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(
            selectedRate: new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25),
        ),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Earlier Purchase Unresolved')
        ->and($result->leavePackageIntact)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('buys again once the stalled purchase is settled', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    ShippingOffer::factory()->declined()->for($package)->create();
    mockShippingAdapter();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(
            selectedRate: new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25),
        ),
    );

    expect($result->success)->toBeTrue();
});

it('buys what the offer says, not what the browser sent back', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $offer = ShippingOffer::factory()->for($package)->create([
        'carrier' => 'MockCarrier',
        'postage_source' => PostageSource::CarrierAccount,
        'service_code' => 'GROUND',
        'service_name' => 'Ground',
        'price' => 7.25,
        'rate_metadata' => ['serviceType' => 'FEDEX_GROUND'],
    ]);

    $bought = null;
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('createShipment')
        ->once()
        ->andReturnUsing(function (ShipRequest $request) use (&$bought): ShipResponse {
            $bought = $request;

            return ShipResponse::success(
                trackingNumber: 'TRACK123',
                cost: 7.25,
                carrier: 'MockCarrier',
                service: 'Ground',
                labelData: base64_encode('label'),
            );
        });
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    // A valid offer identifier paired with rate data someone else wrote. The
    // identifier says which offer; everything else on the way in is description
    // and must not reach the carrier.
    $tampered = RateResponse::fromArray([
        'carrier' => 'MockCarrier',
        'serviceCode' => 'OVERNIGHT',
        'serviceName' => 'Overnight',
        'price' => 0.01,
        'deliveryCommitment' => null,
        'deliveryDate' => null,
        'transitTime' => null,
        'metadata' => ['serviceType' => 'FEDEX_FIRST_OVERNIGHT', 'injected' => 'value'],
        'offerId' => $offer->public_id,
    ]);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $tampered),
    );

    expect($result->success)->toBeTrue()
        ->and($bought->selectedRate->serviceCode)->toBe('GROUND')
        ->and($bought->selectedRate->serviceName)->toBe('Ground')
        ->and($bought->selectedRate->price)->toBe(7.25)
        ->and($bought->selectedRate->metadata)->toBe(['serviceType' => 'FEDEX_GROUND'])
        ->and($result->selectedRate->serviceCode)->toBe('GROUND');
});

it('keeps the offer spendable through a customs weight prompt', function (): void {
    $package = internationalPackageNeedingOverride();
    $offer = ShippingOffer::factory()->for($package)->create(['carrier' => 'MockCarrier', 'postage_source' => PostageSource::CarrierAccount]);
    mockShippingAdapter();

    $workflow = app(PackageShippingWorkflow::class);
    $rate = rateForOffer($offer);

    $prompt = $workflow->ship($package, new PackageShippingRequest(
        selectedRate: $rate,
        requireCustomsWeightOverride: true,
    ));

    // The prompt is a round trip through the operator. Consuming the offer on
    // the way out would leave the confirmed retry with nothing to buy.
    expect($prompt->requiresCustomsWeightOverride)->toBeTrue()
        ->and($offer->fresh()->consumed_at)->toBeNull();

    $confirmed = $workflow->ship($package->fresh(), new PackageShippingRequest(
        selectedRate: $rate,
        overrideCustomsWeights: true,
        requireCustomsWeightOverride: true,
    ));

    expect($confirmed->success)->toBeTrue()
        ->and($offer->fresh()->purchase_reference)->toBe('TRACK123');
});

it('ships a rate carrying no offer exactly as before', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    mockShippingAdapter();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(
            selectedRate: new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25),
        ),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->tracking_number)->toBe('TRACK123')
        ->and(ShippingOffer::count())->toBe(0);
});

it('refuses a channel offer no purchase path can dispatch yet', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);

    // An Amazon offer carried by OnTrac. Buying it means asking Amazon, not
    // looking up "OnTrac" in the carrier registry and finding a direct adapter
    // we have neither built nor hold an account for.
    $offer = ShippingOffer::factory()->for($package)->create([
        'carrier' => 'OnTrac',
        'postage_source' => PostageSource::PostageDataSource,
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('OnTrac', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: rateForOffer($offer)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Rate Not Purchasable')
        ->and($offer->fresh()->consumed_at)->toBeNull();
});

it('refuses an offer quoted before sandbox mode was switched', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $offer = ShippingOffer::factory()->for($package)->create([
        'carrier' => 'MockCarrier',
        'postage_source' => PostageSource::CarrierAccount,
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    Setting::updateOrCreate(['key' => 'sandbox_mode'], ['value' => '1', 'type' => 'boolean', 'group' => 'system']);
    app(SettingsService::class)->clearCache();

    // The tokens in this offer were issued by the production host and mean
    // nothing to the sandbox one.
    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: rateForOffer($offer)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Sandbox Mode Changed')
        ->and($offer->fresh()->consumed_at)->toBeNull();
});

it('refuses to start a second purchase while one is in flight for the package', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $second = null;

    // The re-entrant call stands in for a concurrent request: it arrives while
    // the first purchase is at the carrier, which is exactly the window where
    // two different valid offers would otherwise each buy a label.
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('createShipment')
        ->once()
        ->andReturnUsing(function () use (&$second, $package): ShipResponse {
            $second = app(PackageShippingWorkflow::class)->ship(
                $package,
                new PackageShippingRequest(
                    selectedRate: new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25),
                ),
            );

            return ShipResponse::success(
                trackingNumber: 'TRACK123',
                cost: 7.25,
                carrier: 'MockCarrier',
                service: 'Ground',
                labelData: base64_encode('label'),
            );
        });
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $first = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(
            selectedRate: new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25),
        ),
    );

    expect($first->success)->toBeTrue()
        ->and($second->success)->toBeFalse()
        ->and($second->title)->toBe('Purchase In Progress');
});

it('releases the package lock after a purchase fails', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    mockShippingAdapter(ShipResponse::failure('The carrier rejected the shipment.'));

    $rate = new RateResponse('MockCarrier', 'GROUND', 'Ground', 7.25);
    $workflow = app(PackageShippingWorkflow::class);

    $workflow->ship($package, new PackageShippingRequest(selectedRate: $rate));
    $second = $workflow->ship($package->fresh(), new PackageShippingRequest(selectedRate: $rate));

    // A failed attempt must not leave the package locked for three minutes.
    expect($second->title)->not->toBe('Purchase In Progress');
});

it('carries the quote metadata an adapter cannot buy without', function (): void {
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $offer = ShippingOffer::factory()->for($package)->create([
        'carrier' => 'MockCarrier',
        'postage_source' => PostageSource::CarrierAccount,
        // USPS reads all three of these with no fallback.
        'rate_metadata' => [
            'mailClass' => 'USPS_GROUND_ADVANTAGE',
            'rateIndicator' => 'SP',
            'processingCategory' => 'MACHINABLE',
        ],
    ]);

    $bought = null;
    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('createShipment')
        ->once()
        ->andReturnUsing(function (ShipRequest $request) use (&$bought): ShipResponse {
            $bought = $request;

            return ShipResponse::success(
                trackingNumber: 'TRACK123',
                cost: 7.25,
                carrier: 'MockCarrier',
                service: 'Ground',
                labelData: base64_encode('label'),
            );
        });
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    // The browser sends none of it back, and the purchase still has it.
    $rate = RateResponse::fromArray([
        'carrier' => 'MockCarrier',
        'serviceCode' => 'GROUND',
        'serviceName' => 'Ground',
        'price' => 7.25,
        'deliveryCommitment' => null,
        'deliveryDate' => null,
        'transitTime' => null,
        'offerId' => $offer->public_id,
    ]);

    app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $rate));

    expect($bought->selectedRate->metadata)->toBe([
        'mailClass' => 'USPS_GROUND_ADVANTAGE',
        'rateIndicator' => 'SP',
        'processingCategory' => 'MACHINABLE',
    ]);
});

it('refuses an offer quoted on a carrier account that no longer applies', function (): void {
    $account = createUspsAccount();
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);

    $offer = ShippingOffer::factory()->for($package)->create([
        'carrier' => 'USPS',
        'postage_source' => PostageSource::CarrierAccount,
        'carrier_account_id' => $account->id,
    ]);

    // The account that quoted it stops applying to this package. The adapter
    // resolves its own account from the package, so buying now would bill an
    // account that never offered this price.
    $account->update(['active' => false]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldNotReceive('createShipment');
    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: rateForOffer($offer)),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Carrier Account Changed')
        ->and($offer->fresh()->consumed_at)->toBeNull();
});

it('buys on the account that quoted the offer', function (): void {
    $account = createUspsAccount();
    $package = Package::factory()->create(['status' => PackageStatus::Unshipped]);

    $offer = ShippingOffer::factory()->for($package)->create([
        'carrier' => 'USPS',
        'postage_source' => PostageSource::CarrierAccount,
        'carrier_account_id' => $account->id,
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('createShipment')->once()->andReturn(
        ShipResponse::success(
            trackingNumber: 'TRACK123',
            cost: 7.25,
            carrier: 'USPS',
            service: 'Ground Advantage',
            labelData: base64_encode('label'),
        )
    );
    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: rateForOffer($offer)),
    );

    expect($result->success)->toBeTrue();
});
