<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CarrierPackaging;
use App\Enums\OfferRejection;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Filament\Pages\Ship;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Product;
use App\Models\RateQuote;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\PostageSources\OfferStore;
use App\Services\ShipDateService;
use App\Services\ShippingRateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * Direct-carrier rates behind an opaque identifier — `postage-source-split/14`.
 *
 * Every rate the rate service returns gets a `ShippingOffer` row with no
 * purchase context; the browser names the row; the purchase restores carrier,
 * service, price and metadata from it, exactly as it does for an Amazon offer.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    Cache::flush();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A package on a method that can buy from a USPS account, quoted by the fake
 * USPS adapter — which now resolves its account the way the real one does.
 *
 * @return array{package: Package, account: CarrierAccount}
 */
function packageQuotedByFakeUsps(): array
{
    $carrier = Carrier::factory()->usps()->create(['active' => true]);
    $account = CarrierAccount::factory()->create(['carrier_id' => $carrier->id, 'active' => true]);
    CarrierAccountScope::factory()->forAccount($account)->global()->create();

    $method = ShippingMethod::factory()->create();

    foreach (['USPS_GROUND_ADVANTAGE' => 'Ground Advantage', 'PRIORITY_MAIL' => 'Priority Mail'] as $code => $name) {
        $service = CarrierService::factory()->create([
            'carrier_id' => $carrier->id,
            'name' => $name,
            'service_code' => $code,
            'active' => true,
        ]);
        $method->carrierServices()->attach($service->id);
    }

    $product = Product::factory()->create(['weight' => 1.5]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
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

    app(CarrierRegistry::class)->registerInstance('USPS', new FakeCarrierAdapter('USPS'));

    return ['package' => $package, 'account' => $account];
}

/**
 * A package on a mock carrier, for the tests that need to see exactly what
 * reaches the adapter.
 */
function packageOnMockCarrier(): Package
{
    $carrier = Carrier::factory()->create(['name' => 'MockCarrier', 'active' => true]);
    $service = CarrierService::factory()->create([
        'carrier_id' => $carrier->id,
        'name' => 'Priority Mail',
        'service_code' => 'PRIORITY_MAIL',
        'active' => true,
    ]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service->id);

    $product = Product::factory()->create(['weight' => 1.5]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
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
 * A quoting mock that returns the given rates and ships whatever it is handed,
 * recording the ship request so a test can see what the purchase sent.
 *
 * @param  list<RateResponse>  $rates
 */
function mockCarrierQuoting(array $rates, ?ShipRequest &$sent = null): void
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect($rates));
    $adapter->shouldReceive('createShipment')->andReturnUsing(function (ShipRequest $request) use (&$sent): ShipResponse {
        $sent = $request;

        return ShipResponse::success(
            trackingNumber: 'MOCK123',
            cost: $request->selectedRate->price,
            carrier: 'MockCarrier',
            service: $request->selectedRate->serviceName,
            labelData: base64_encode('label'),
        );
    });

    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);
}

/**
 * The rate at this index as the Ship page would send it back.
 */
function rateOptionFromShipPage(Package $package, int $index = 0): RateResponse
{
    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    return RateResponse::fromArray($options->rateOptions[$index]);
}

it('quotes a direct rate behind an offer that records everything the purchase needs', function (): void {
    ['package' => $package, 'account' => $account] = packageQuotedByFakeUsps();

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    expect($rates)->not->toBeEmpty()
        ->and($rates->every(fn (RateResponse $rate): bool => $rate->offerId !== null))->toBeTrue()
        ->and($rates->every(fn (RateResponse $rate): bool => $rate->carrierAccountId === $account->id))->toBeTrue();

    $rate = $rates->first();
    $offer = ShippingOffer::where('public_id', $rate->offerId)->firstOrFail();
    $quote = RateQuote::where('package_id', $package->id)
        ->where('service_code', $rate->serviceCode)
        ->firstOrFail();

    $shipDay = app(ShipDateService::class)->getShipDate(Carrier::where('name', Carrier::USPS)->first(), $package->location_id);

    expect($offer->package_id)->toBe($package->id)
        ->and($offer->postage_source)->toBe(PostageSource::CarrierAccount)
        ->and($offer->carrier_account_id)->toBe($account->id)
        ->and($offer->postage_data_source_id)->toBeNull()
        ->and($offer->purchase_context)->toBeNull()
        ->and($offer->carrier)->toBe('USPS')
        ->and($offer->service_code)->toBe($rate->serviceCode)
        ->and($offer->service_name)->toBe($rate->serviceName)
        ->and((float) $offer->price)->toBe($rate->price)
        ->and($offer->rate_metadata)->toMatchArray($rate->metadata)
        ->and($offer->rate_quote_id)->toBe($quote->id)
        ->and($offer->rateQuote->is($quote))->toBeTrue()
        ->and($offer->consumed_at)->toBeNull()
        // Bound to what was priced and to who would be billed.
        ->and($offer->quote_fingerprint)->toBe(RateRequest::fromPackage($package->fresh())->fingerprint())
        ->and($offer->carrier_account_fingerprint)->toBe($account->fingerprint())
        // The window closes with the quoted ship day, in the location's
        // timezone; the column carries whole seconds.
        ->and($offer->expires_at->timestamp)->toBe($shipDay->endOfDay()->timestamp)
        ->and($offer->expires_at->isFuture())->toBeTrue();

    // One offer per rate, one quote per rate, and every rate the Ship page
    // lists carries its identifier.
    expect(ShippingOffer::where('package_id', $package->id)->count())->toBe($rates->count())
        ->and(RateQuote::where('package_id', $package->id)->count())->toBe($rates->count());

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect(collect($options->rateOptions)->every(fn (array $option): bool => filled($option['offerId'])))->toBeTrue();
});

it('windows a direct offer on the day the carrier was quoted for, read once', function (): void {
    ['package' => $package] = packageQuotedByFakeUsps();

    // The ship date is read exactly once per carrier and shared between the
    // rate request and the offer window. Read twice, a pickup cutoff or an
    // End of Day run landing between the carrier call and the offer would
    // give the offer a later day than the price was quoted for.
    $quotedFor = CarbonImmutable::now('America/New_York')->addDays(3)->startOfDay();

    $this->partialMock(ShipDateService::class, function (MockInterface $mock) use ($quotedFor): void {
        $mock->shouldReceive('getShipDate')
            ->once()
            ->with(Mockery::on(fn (?Carrier $carrier): bool => $carrier?->name === Carrier::USPS), Mockery::any())
            ->andReturn($quotedFor);
    });

    $rates = app(ShippingRateService::class)->getShippingRates($package->id);

    $offer = ShippingOffer::where('public_id', $rates->first()->offerId)->firstOrFail();

    expect($offer->expires_at->timestamp)->toBe($quotedFor->endOfDay()->timestamp);
});

it('buys what the offer says when the browser restates the rate', function (): void {
    $this->actingAs($user = User::factory()->create());
    ['package' => $package] = packageQuotedByFakeUsps();

    $quoted = rateOptionFromShipPage($package);

    // The browser holds the identifier and a description. Here it lies
    // about all of the description: a lower price, another service, and the
    // metadata the adapter reads with no fallback.
    $tampered = RateResponse::fromArray([
        ...$quoted->toArray(),
        'price' => 0.01,
        'serviceCode' => 'PRIORITY_MAIL_EXPRESS',
        'serviceName' => 'Priority Mail Express',
        'metadata' => ['mailClass' => 'PRIORITY_MAIL_EXPRESS', 'rateIndicator' => 'PA'],
    ]);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $tampered, userId: $user->id),
    );

    $offer = ShippingOffer::where('public_id', $quoted->offerId)->firstOrFail();

    expect($result->success)->toBeTrue()
        ->and($result->selectedRate->serviceCode)->toBe($offer->service_code)
        ->and($result->selectedRate->price)->toBe((float) $offer->price)
        ->and($result->selectedRate->metadata)->toBe($offer->rate_metadata)
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and((float) $package->fresh()->cost)->toBe((float) $offer->price)
        ->and($package->fresh()->service)->toBe($offer->service_name)
        ->and($offer->fresh()->consumed_at)->not->toBeNull()
        ->and($offer->fresh()->purchase_reference)->toBe($package->fresh()->tracking_number);
});

it('classifies the packaging of the restored rate, not the one the browser sent', function (): void {
    // The authoritative check the reviewer asked for. The package stays in
    // the packer's own box throughout — moving it would change what was
    // priced and retire the offer before this check ran — and the browser's
    // copy of the rate says single-piece. The adapter is asked about the
    // offer's metadata, which says flat-rate box, and refuses.
    $this->actingAs($user = User::factory()->create());
    $package = packageOnMockCarrier();

    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    // Stamped as shipper packaging at quote time so rate shopping's filter
    // lets it onto the list; the purchase asks the adapter again, and the
    // adapter reads the offer's `FB` and answers with the box it names.
    $adapter->shouldReceive('getRates')->andReturn(collect([
        new RateResponse(
            carrier: 'MockCarrier',
            serviceCode: 'PRIORITY_MAIL',
            serviceName: 'Priority Mail Medium Flat Rate Box',
            price: 18.40,
            metadata: ['mailClass' => 'PRIORITY_MAIL', 'rateIndicator' => 'FB'],
            packagingRequirement: PackagingRequirement::shipperPackaging(),
        ),
    ]));
    $adapter->shouldReceive('packagingRequirementFor')
        ->once()
        ->withArgs(fn (RateResponse $rate): bool => ($rate->metadata['rateIndicator'] ?? null) === 'FB')
        ->andReturn(PackagingRequirement::exactly(CarrierPackaging::UspsMediumFlatRateBox));
    $adapter->shouldReceive('createShipment')->never()->andReturn(ShipResponse::failure('unexpected'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $quoted = rateOptionFromShipPage($package);

    // The browser's copy of the rate claims it never needed a box.
    $browserRate = RateResponse::fromArray([
        ...$quoted->toArray(),
        'metadata' => ['mailClass' => 'PRIORITY_MAIL', 'rateIndicator' => 'SP'],
        'packagingRequirement' => PackagingRequirement::shipperPackaging()->toArray(),
    ]);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $browserRate, userId: $user->id),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Packaging Mismatch')
        ->and($result->message)->toContain('USPS Medium Flat Rate Box')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('refuses an offer once the package has been edited, and the Ship page re-quotes', function (): void {
    $this->actingAs(User::factory()->create());
    $package = packageOnMockCarrier();
    mockCarrierQuoting([new RateResponse('MockCarrier', 'PRIORITY_MAIL', 'Priority Mail', 9.65)]);

    $quoted = rateOptionFromShipPage($package);

    // The weight is what the carrier priced.
    $package->update(['weight' => 4.0]);

    $inspection = app(OfferStore::class)->inspect($package->fresh(), $quoted->offerId);

    expect($inspection->wasRejected())->toBeTrue()
        ->and($inspection->rejection)->toBe(OfferRejection::PackageChanged)
        ->and($inspection->requiresRequote())->toBeTrue();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(selectedRate: $quoted),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Package Changed')
        ->and($result->message)->toContain('Get rates again')
        ->and($result->requiresRequote)->toBeTrue()
        ->and(ShippingOffer::where('public_id', $quoted->offerId)->value('consumed_at'))->toBeNull()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('re-quotes on the Ship page when the offer it holds is for an edited package', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $package = packageOnMockCarrier();
    mockCarrierQuoting([new RateResponse('MockCarrier', 'PRIORITY_MAIL', 'Priority Mail', 9.65)]);

    $component = Livewire::test(Ship::class, ['package_id' => $package->id]);
    $staleOfferId = $component->get('rateOptions')[0]['offerId'];

    expect($staleOfferId)->not->toBeNull();

    // The tab stays open while someone edits the shipment's address.
    $package->shipment->update(['city' => 'Elsewhere']);

    $component->set('selectedRateIndex', 0)
        ->call('ship')
        ->assertNotified()
        ->assertNotDispatched('print-label');

    $freshOfferId = $component->get('rateOptions')[0]['offerId'];

    expect($freshOfferId)->not->toBeNull()
        ->and($freshOfferId)->not->toBe($staleOfferId)
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and(ShippingOffer::where('public_id', $staleOfferId)->value('consumed_at'))->toBeNull();
});

it('lets one direct offer be claimed once, so two purchases of it cannot both buy', function (): void {
    $package = packageOnMockCarrier();
    $rate = new RateResponse('MockCarrier', 'PRIORITY_MAIL', 'Priority Mail', 9.65);
    $quoted = quotedDirectly($package, $rate);

    $store = app(OfferStore::class);

    $first = $store->redeem($package, $quoted->offerId);
    $second = $store->redeem($package, $quoted->offerId);

    expect($first->wasRejected())->toBeFalse()
        ->and($second->wasRejected())->toBeTrue()
        ->and($second->rejection)->toBe(OfferRejection::AlreadyConsumed);
});

it('refuses the second purchase of a direct offer already spent', function (): void {
    // The carrier declines the first attempt, so the offer is consumed and
    // settled and the package is still unshipped. A double-click that lands
    // after that names a spent offer and buys nothing — and, because the
    // decline proves nothing was bought, is sent to a re-quote rather than
    // to look for a label.
    $package = packageOnMockCarrier();
    $quoted = quotedDirectly($package, new RateResponse('MockCarrier', 'PRIORITY_MAIL', 'Priority Mail', 9.65));

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn(ShipResponse::failure('Address not found.'));
    app(CarrierRegistry::class)->registerInstance('MockCarrier', $adapter);

    $first = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $quoted));
    $second = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $quoted));

    expect($first->success)->toBeFalse()
        ->and($first->title)->toBe('Shipping Error')
        ->and($second->success)->toBeFalse()
        ->and($second->title)->toBe('Purchase Declined')
        ->and($second->requiresRequote)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('marks exactly the quoted variant selected when two share a carrier and service code', function (): void {
    $this->actingAs(User::factory()->create());
    $package = packageOnMockCarrier();

    // Two USPS-shaped variants of one mail class: single-piece and cubic. A
    // match on carrier and service code would mark both.
    mockCarrierQuoting([
        new RateResponse('MockCarrier', 'PRIORITY_MAIL', 'Priority Mail', 9.65, metadata: ['mailClass' => 'PRIORITY_MAIL', 'rateIndicator' => 'SP']),
        new RateResponse('MockCarrier', 'PRIORITY_MAIL', 'Priority Mail Cubic', 8.10, metadata: ['mailClass' => 'PRIORITY_MAIL', 'rateIndicator' => 'CP']),
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);
    $cubic = collect($options->rateOptions)->firstWhere('serviceName', 'Priority Mail Cubic');

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: RateResponse::fromArray($cubic)),
    );

    $quotes = RateQuote::where('package_id', $package->id)->orderBy('id')->get();
    $selected = $quotes->where('selected', true);

    expect($result->success)->toBeTrue()
        ->and($quotes)->toHaveCount(2)
        ->and($selected)->toHaveCount(1)
        ->and($selected->first()->service_name)->toBe('Priority Mail Cubic')
        ->and((float) $selected->first()->quoted_price)->toBe(8.10);
});

it('auto ships through rate shopping as before, spending the offer it was issued', function (): void {
    // The unattended path never round-trips through a browser, but the
    // purchase path is shared: the rate it buys carries the offer the shared
    // loop issued, and that offer is spent and tied to the label.
    $this->actingAs($user = User::factory()->create());
    ['package' => $package] = packageQuotedByFakeUsps();

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(userId: $user->id, cleanupOnFailure: false),
    );

    $spent = ShippingOffer::where('package_id', $package->id)->whereNotNull('consumed_at')->get();

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($spent)->toHaveCount(1)
        ->and($spent->first()->postage_source)->toBe(PostageSource::CarrierAccount)
        ->and($spent->first()->purchase_reference)->toBe($package->fresh()->tracking_number)
        ->and(RateQuote::where('package_id', $package->id)->where('selected', true)->count())->toBe(1)
        ->and(RateQuote::where('package_id', $package->id)->where('selected', true)->value('id'))->toBe($spent->first()->rate_quote_id);
});

it('refuses a direct offer once the quoting account bills as someone else', function (): void {
    // Same account row, different payer: the adapters read the account
    // number fresh at purchase, so the offer's price would land on an
    // account that never quoted it.
    $this->actingAs($user = User::factory()->create());
    ['package' => $package, 'account' => $account] = packageQuotedByFakeUsps();

    $quoted = rateOptionFromShipPage($package);

    $account->mergeCredential('eps_account', '99999999');
    $account->save();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $quoted, userId: $user->id),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Carrier Account Changed')
        ->and($result->message)->toContain('account details changed')
        ->and(ShippingOffer::where('public_id', $quoted->offerId)->value('consumed_at'))->toBeNull()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('keeps a direct offer across a token refresh on the quoting account', function (): void {
    // OAuthService writes refreshed tokens into the secret credentials. The
    // payer has not changed, so neither has the quote.
    $this->actingAs($user = User::factory()->create());
    ['package' => $package, 'account' => $account] = packageQuotedByFakeUsps();

    $quoted = rateOptionFromShipPage($package);

    $account->mergeSecret('oauth_token', 'refreshed-'.fake()->sha256());
    $account->mergeSecret('client_secret', 'rotated-'.fake()->sha256());
    $account->save();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(selectedRate: $quoted, userId: $user->id),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('refuses a direct offer once the shipment moves to a method that excludes its carrier', function (): void {
    // The Ship page quoted USPS under a method that permits it. The shipment
    // is then moved to a UPS-only method; the USPS offer is still on file,
    // unexpired, and the tab still holds its id.
    $this->actingAs($user = User::factory()->create());
    ['package' => $package] = packageQuotedByFakeUsps();

    $quoted = rateOptionFromShipPage($package);

    $ups = Carrier::factory()->create(['name' => 'UPS', 'active' => true]);
    $upsOnly = ShippingMethod::factory()->create();
    $upsOnly->carrierServices()->attach(CarrierService::factory()->create([
        'carrier_id' => $ups->id,
        'name' => 'UPS Ground',
        'service_code' => '03',
        'active' => true,
    ])->id);
    $package->shipment->update(['shipping_method_id' => $upsOnly->id]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->never()->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->never()->andReturn(ShipResponse::failure('unexpected'));
    app(CarrierRegistry::class)->reset();
    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(selectedRate: $quoted, userId: $user->id),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Package Changed')
        ->and($result->requiresRequote)->toBeTrue()
        ->and(ShippingOffer::where('public_id', $quoted->offerId)->value('consumed_at'))->toBeNull()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});
