<?php

use App\Contracts\AsyncRateQuoting;
use App\Contracts\CarrierAdapterInterface;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Enums\ServiceCapability;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\CarrierServiceSpecialService;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\SpecialService;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\ShipmentImport\AmazonOrderItems;
use App\Services\ShippingRateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

/**
 * `carrier-catalog-reset/06`: rating resolves the package's postage sources
 * first, then asks each one for the method's services it can sell (ADR-0006
 * decisions 3, 4 and 10).
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    Cache::flush();

    $this->usps = Carrier::factory()->usps()->create(['active' => true]);
    $this->method = ShippingMethod::factory()->create();
    $this->groundAdvantage = CarrierService::factory()->uspsGroundAdvantage()->for($this->usps)->create();
    $this->method->carrierServices()->attach($this->groundAdvantage->id);
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

function sourceFirstPackage(ShippingMethod $method, array $shipment = []): Package
{
    return Package::factory()
        ->for(Shipment::factory()->for($method)->create(['postal_code' => '90210', ...$shipment]))
        ->create([
            'box_size_id' => BoxSize::factory()->create()->id,
            'weight' => 2.0,
            'height' => 10,
            'width' => 8,
            'length' => 6,
            'status' => PackageStatus::Unshipped,
        ]);
}

function globalUspsAccount(Carrier $usps, string $name = 'USPS', bool $rateShop = false, ?Location $location = null): CarrierAccount
{
    $account = CarrierAccount::factory()->create(['carrier_id' => $usps->id, 'name' => $name, 'active' => true]);

    CarrierAccountScope::create([
        'carrier_account_id' => $account->id,
        'location_id' => $location?->id,
        'client_id' => null,
        'rate_shop' => $rateShop,
    ]);

    return $account;
}

/**
 * A USPS integration that quotes Ground Advantage on whatever account it is
 * handed, and records the requests it was asked with.
 *
 * @param  array<int, RateRequest>  $requests
 */
function uspsQuotingOnHandedAccount(array &$requests, ServiceCapability $capability = ServiceCapability::Supported): MockInterface
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('offerCapability')->andReturn($capability);
    $adapter->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturnUsing(function (RateRequest $request) use (&$requests): Collection {
        $requests[] = $request;

        return collect([new RateResponse(
            'USPS',
            'USPS_GROUND_ADVANTAGE',
            'Ground Advantage',
            8.50,
            packagingRequirement: PackagingRequirement::shipperPackaging(),
            carrierAccountId: $request->carrierAccount?->id,
        )]);
    });

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    return $adapter;
}

/**
 * An Amazon source that answers nothing, so a test can see whether it was asked.
 */
function silentAmazonSource(string $registryName = AmazonBuyShippingAdapter::SOURCE_NAME): MockInterface
{
    $amazon = Mockery::mock(CarrierAdapterInterface::class.', '.AsyncRateQuoting::class);
    $amazon->shouldReceive('isConfigured')->andReturnTrue();
    $amazon->shouldReceive('offerCapability')->andReturn(ServiceCapability::Supported);
    $amazon->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $amazon->shouldReceive('prepareRateRequest')->andReturnUsing(fn (): mixed => null)->byDefault();
    $amazon->shouldReceive('getRates')->andReturnUsing(fn (): Collection => collect())->byDefault();

    app(CarrierRegistry::class)->registerInstance($registryName, $amazon);

    return $amazon;
}

function amazonHookService(): CarrierService
{
    return CarrierService::factory()
        ->for(Carrier::firstOrCreate(['name' => AmazonBuyShippingAdapter::SOURCE_NAME]))
        ->create(['service_code' => 'AMAZON_BUY_SHIPPING', 'name' => 'Amazon Buy Shipping']);
}

it('resolves the package sources once per quote', function (): void {
    globalUspsAccount($this->usps);
    app(CarrierRegistry::class)->registerInstance('USPS', new FakeCarrierAdapter('USPS'));

    $resolver = Mockery::mock(PostageSourceResolver::class, [app(CarrierRegistry::class), app(AmazonOrderItems::class)])
        ->makePartial();
    $resolver->shouldReceive('resolve')->once()->passthru();
    app()->instance(PostageSourceResolver::class, $resolver);

    expect(app(ShippingRateService::class)->getShippingRates(sourceFirstPackage($this->method)->id))->toHaveCount(1);
});

it('rates a direct carrier on the account the resolver handed it', function (): void {
    $account = globalUspsAccount($this->usps);
    $requests = [];
    uspsQuotingOnHandedAccount($requests);
    $package = sourceFirstPackage($this->method);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);
    $offer = ShippingOffer::where('public_id', $rates->first()->offerId)->firstOrFail();

    expect($requests)->toHaveCount(1)
        ->and($requests[0]->carrierAccount?->id)->toBe($account->id)
        ->and($rates->first()->carrierAccountId)->toBe($account->id)
        ->and($offer->carrier_account_id)->toBe($account->id)
        // The account is bound by the offer's own id and billing fingerprint,
        // not by the quote fingerprint, which is unchanged.
        ->and($offer->quote_fingerprint)->toBe(RateRequest::fromPackage($package->fresh())->fingerprint());
});

it('leaves the account out of the quote fingerprint', function (): void {
    $account = globalUspsAccount($this->usps);
    $request = RateRequest::fromPackage(sourceFirstPackage($this->method));

    expect($request->withCarrierAccount($account)->fingerprint())->toBe($request->fingerprint());
});

it('lets an integration quote on the handed account rather than resolving its own', function (): void {
    $scoped = globalUspsAccount($this->usps, 'Scoped USPS');
    $handed = CarrierAccount::factory()->create(['carrier_id' => $this->usps->id, 'name' => 'Handed USPS', 'active' => true]);
    $request = RateRequest::fromPackage(sourceFirstPackage($this->method));

    $adapter = new FakeCarrierAdapter('USPS');

    expect($adapter->getRates($request, [])->first()->carrierAccountId)->toBe($scoped->id)
        ->and($adapter->getRates($request->withCarrierAccount($handed), [])->first()->carrierAccountId)->toBe($handed->id);
});

it('quotes one account under a rate-shopping scope, and buys its offer', function (): void {
    $this->actingAs($user = User::factory()->create());
    $location = Location::factory()->create();
    $winner = globalUspsAccount($this->usps, 'Location USPS', rateShop: true, location: $location);
    globalUspsAccount($this->usps, 'Global USPS');
    app(CarrierRegistry::class)->registerInstance('USPS', new FakeCarrierAdapter('USPS'));

    $package = sourceFirstPackage($this->method);
    $package->update(['location_id' => $location->id]);

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->toHaveCount(1)
        ->and($rates->first()->carrierAccountId)->toBe($winner->id);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(selectedRate: $rates->first(), userId: $user->id),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('does not ask Amazon Shipping when the method does not list it', function (): void {
    $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
    CarrierAccountScope::create(['data_source_id' => $connection->id]);

    app(CarrierRegistry::class)->registerInstance('USPS', new FakeCarrierAdapter('USPS'));
    $amazon = silentAmazonSource(Carrier::AMAZON_SHIPPING);
    $amazon->shouldNotReceive('prepareRateRequest');
    $amazon->shouldNotReceive('getRates');

    $rates = app(ShippingRateService::class)->getShippingRates(sourceFirstPackage($this->method)->id);

    expect($rates->pluck('carrier')->unique()->all())->toBe(['USPS']);
});

it('asks Amazon Shipping through the scoped connection when the method lists it, and not Buy Shipping', function (): void {
    $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
    CarrierAccountScope::create(['data_source_id' => $connection->id]);
    $this->method->carrierServices()->attach([amazonShippingGround()->id, amazonHookService()->id]);

    app(CarrierRegistry::class)->registerInstance('USPS', new FakeCarrierAdapter('USPS'));
    $buyShipping = silentAmazonSource();
    $buyShipping->shouldNotReceive('getRates');
    $amazon = silentAmazonSource(Carrier::AMAZON_SHIPPING);
    $amazon->shouldReceive('getRates')
        ->once()
        ->withArgs(fn (RateRequest $request, array $serviceCodes): bool => $serviceCodes === ['std-us-swa-mfn'])
        ->andReturn(collect());

    app(ShippingRateService::class)->getShippingRates(sourceFirstPackage($this->method)->id);
});

it('applies special-service scoping to direct sources and never to Amazon', function (): void {
    $signature = SpecialService::create([
        'code' => 'signature_required',
        'name' => 'Signature Required',
        'scope' => 'shipment',
        'category' => 'delivery',
        'requires_value' => false,
        'active' => true,
    ]);
    $this->method->specialServices()->attach($signature->id, ['mode' => 'required']);

    // Signature is scoped to a service of each carrier that the method does
    // not list, so neither carrier's listed service is scoped for it.
    $hook = amazonHookService();
    $this->method->carrierServices()->attach($hook->id);
    foreach ([
        CarrierService::factory()->uspsPriority()->for($this->usps)->create(),
        CarrierService::factory()->for($hook->carrier)->create(['service_code' => 'AMAZON_OTHER']),
    ] as $scopedService) {
        CarrierServiceSpecialService::create([
            'carrier_service_id' => $scopedService->id,
            'special_service_id' => $signature->id,
        ]);
    }

    $requests = [];
    uspsQuotingOnHandedAccount($requests);
    $amazon = silentAmazonSource();
    $amazon->shouldReceive('getRates')
        ->once()
        ->withArgs(fn (RateRequest $request): bool => $request->hasSpecialService('signature_required'))
        ->andReturn(collect());

    $origin = DataSource::factory()->amazon()->create(['active' => true]);
    $service = app(ShippingRateService::class);
    $service->getShippingRates(sourceFirstPackage($this->method, [
        'data_source_id' => $origin->id,
        'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
    ])->id);

    // Amazon is still asked, with the signature requirement: its offers are
    // judged one by one on the value-added services each returns.
    expect($requests)->toBeEmpty()
        ->and($service->getExclusions())->toBe([[
            'carrier' => 'USPS',
            'source' => 'USPS',
            'reason' => 'USPS has no services that support Signature Required for this destination.',
        ]]);
});

it('names a carrier in an exclusion by its display name', function (): void {
    $this->usps->update(['display_name' => 'US Postal Service']);
    $signature = SpecialService::create([
        'code' => 'signature_required',
        'name' => 'Signature Required',
        'scope' => 'shipment',
        'category' => 'delivery',
        'requires_value' => false,
        'active' => true,
    ]);
    $this->method->specialServices()->attach($signature->id, ['mode' => 'required']);

    $requests = [];
    uspsQuotingOnHandedAccount($requests, ServiceCapability::Prohibited);

    $service = app(ShippingRateService::class);
    $service->getShippingRates(sourceFirstPackage($this->method)->id);

    expect($service->getExclusions())->toBe([[
        'carrier' => 'US Postal Service',
        'source' => 'USPS',
        'reason' => 'US Postal Service does not support Signature Required.',
    ]]);
});
