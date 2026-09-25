<?php

use App\Contracts\BlindPurchaseSource;
use App\Contracts\CarrierAdapterInterface;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\CarrierPackaging;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Enums\ServiceEvidence;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Exceptions\ShopifyDeclaredWeightException;
use App\Filament\Pages\Ship;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\SpecialService;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\RuleEvaluator;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShippingRateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery\MockInterface;
use Saloon\Laravel\Facades\Saloon;

/**
 * Shopify Shipping as ADR-0003 decisions 5 and 6 govern it: a priceless offer
 * presented beside the rates, and only for a client that has opted in. A person
 * confirms an attended choice; automation requires an explicit rule or a sole
 * eligible configured selection.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

it('offers a blind purchase beside the rates and never among them', function (): void {
    $package = blindPurchasePackage(withUspsRate: true);
    allowBlindPurchase($package);
    registerUspsRate();
    registerBlindSource();

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect($options->rateOptions)->toHaveCount(1)
        ->and($options->rateOptions[0]['carrier'])->toBe('USPS')
        // The pre-selection lands on the only real rate. Nothing pre-selects a
        // blind purchase, ever.
        ->and($options->selectedRateIndex)->toBe(0)
        ->and($options->blindPurchaseOffers)->toHaveCount(1)
        ->and($options->blindPurchaseOffers[0]['source'])->toBe('Shopify')
        ->and($options->blindPurchaseOffers[0]['id'])->toBe('Shopify:auto');
});

it('buys nothing until the packer confirms the blind purchase', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    registerBlindSource();

    $page = Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSet('rateOptions', [])
        ->assertSet('selectedBlindOfferId', null)
        ->set('selectedBlindOfferId', 'Shopify:auto')
        ->call('ship')
        ->assertDispatched('open-modal', id: 'blind-purchase-confirm');

    // Nothing bought yet: the modal is the whole of the answer to "ship".
    expect($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $page->call('confirmBlindPurchase');
    expect($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($package->fresh()->cost)->toBeNull()
        ->and($package->fresh()->service)->toBeNull()
        // What Shopify was asked for is a preference, never the service
        // bought, so the Label names no catalog service (ADR-0003 decision 7).
        ->and($package->fresh()->activeLabel->carrier_service_id)->toBeNull();
});

it('buys the offer the page advertised, not a rate the browser described', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $source = registerBlindSource();
    $seen = null;
    $source->shouldReceive('createShipment')
        ->andReturnUsing(function (ShipRequest $request) use (&$seen): ShipResponse {
            $seen = $request;

            return blindShipResponse();
        });

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->set('selectedBlindOfferId', 'Shopify:auto')
        ->call('ship')
        ->call('confirmBlindPurchase');

    expect($seen)->not->toBeNull()
        ->and($seen->selectedRate)->toBeNull()
        ->and($seen->blindOffer?->serviceCode)->toBe('auto')
        // Nothing is asked for that the source cannot promise to apply.
        ->and($seen->specialServiceCodes)->toBe([]);
});

it('selects one thing at a time', function (): void {
    $package = blindPurchasePackage(withUspsRate: true);
    allowBlindPurchase($package);
    registerUspsRate();
    registerBlindSource();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSet('selectedRateIndex', 0)
        ->set('selectedBlindOfferId', 'Shopify:auto')
        ->assertSet('selectedRateIndex', null)
        ->set('selectedRateIndex', 0)
        ->assertSet('selectedBlindOfferId', null);
});

it('refuses a blind purchase for a client that has not opted in', function (): void {
    $package = blindPurchasePackage();
    $source = registerBlindSource();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(blindOffer: shopifyBlindOffer()),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Blind Purchase Not Enabled')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('refuses a blind purchase when the shipment hard-requires a special service', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $source = registerBlindSource();

    $signature = SpecialService::create([
        'code' => 'signature_required',
        'name' => 'Signature Required',
        'scope' => 'shipment',
        'category' => 'delivery',
        'requires_value' => false,
        'active' => true,
    ]);
    $package->shipment->shippingMethod->specialServices()->attach($signature->id, ['mode' => 'required']);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(blindOffer: shopifyBlindOffer()),
    );

    // The refusal comes from re-deriving what is on offer, so it carries rate
    // shopping's own reason rather than a second wording of the same rule.
    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Offer No Longer Available')
        ->and($result->message)->toContain('cannot guarantee Signature Required')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('gives the refusal its reason when the Shopify row carries a display name', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    registerBlindSource();
    Carrier::where('name', ShopifyAdapter::CARRIER_NAME)->update(['display_name' => 'Shopify Shipping']);

    $signature = SpecialService::create([
        'code' => 'signature_required',
        'name' => 'Signature Required',
        'scope' => 'shipment',
        'category' => 'delivery',
        'requires_value' => false,
        'active' => true,
    ]);
    $package->shipment->shippingMethod->specialServices()->attach($signature->id, ['mode' => 'required']);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(blindOffer: shopifyBlindOffer()),
    );

    // Matched by the source's registry name, while the text names it as the
    // operator labelled it.
    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Shopify Shipping cannot guarantee Signature Required — it picks the carrier and service after the label is bought.');
});

it('refuses a selection the source never advertised', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $source = registerBlindSource();

    // `blindPurchaseOffers` is public Livewire state, so a crafted request can
    // name any selection it likes. Only the server's own list decides.
    $forged = new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Shopify Shipping',
        serviceCode: 'usps:usps_priority_mail_express',
        selectionLabel: 'USPS Priority Mail Express',
    );

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(blindOffer: $forged));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Offer No Longer Available')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('refuses an offer attributed to a source that never offered it', function (): void {
    $package = blindPurchasePackage(withUspsRate: true);
    allowBlindPurchase($package);
    registerUspsRate();
    $source = registerBlindSource();

    $forged = new BlindPurchaseOffer(
        source: 'USPS',
        sourceLabel: 'USPS',
        serviceCode: 'USPS_GROUND_ADVANTAGE',
        selectionLabel: 'Ground Advantage',
    );

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(blindOffer: $forged));

    // USPS quotes; it sells nothing blind, so nothing it lists can be bought
    // this way — and the rate adapter is never reached with a priceless request.
    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Offer No Longer Available')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('refuses to buy a second Shopify label for a shipment that already has one', function (): void {
    // The real adapter, not the stand-in: the withdrawal being tested is its
    // own gate, and the point is that re-deriving the offers at purchase time
    // enforces it even for a Ship page that listed Shopify before the first
    // package went out.
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    app(CarrierRegistry::class)->registerInstance('Shopify', new ShopifyAdapter);
    Package::factory()->shipped()->create(['shipment_id' => $package->shipment_id]);

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(blindOffer: shopifyBlindOffer()));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Offer No Longer Available')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('refuses a blind purchase while another package on the shipment is buying one', function (): void {
    // The per-package lock does not cover this: two packages hold two different
    // package locks and neither sibling has left a trace yet, so without a
    // shipment-level lock both would buy against the same fulfillment order.
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $source = registerBlindSource();

    $held = Cache::lock("shipment-blind-purchase:{$package->shipment_id}", 180);
    expect($held->get())->toBeTrue();

    try {
        $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(blindOffer: shopifyBlindOffer()));
    } finally {
        $held->release();
    }

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Purchase In Progress')
        ->and($result->message)->toContain('buys against the whole order')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('lets a carrier account sell postage while a blind purchase is in flight elsewhere on the shipment', function (): void {
    // The shipment lock covers the shared fulfillment order, not the shipment.
    // Postage bought from a carrier account touches nothing another package is
    // using, and refusing it would strand the second parcel.
    $package = blindPurchasePackage();
    allowBlindPurchase($package);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('createShipment')->once()->andReturn(ShipResponse::success(
        trackingNumber: '9400111899223197428490',
        cost: 8.50,
        carrier: 'USPS',
        service: 'Ground Advantage',
        labelData: base64_encode('LABEL-BYTES'),
    ));
    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $held = Cache::lock("shipment-blind-purchase:{$package->shipment_id}", 180);
    expect($held->get())->toBeTrue();

    try {
        $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(
            selectedRate: quotedDirectly($package, new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 8.50)),
        ));
    } finally {
        $held->release();
    }

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('buys the server\'s copy of the offer, not the one the browser described', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $source = registerBlindSource();
    $seen = null;
    $source->shouldReceive('createShipment')
        ->andReturnUsing(function (ShipRequest $request) use (&$seen): ShipResponse {
            $seen = $request;

            return blindShipResponse();
        });

    // Same identifier, everything else rewritten. The identifier is all that
    // survives the trip.
    $tampered = new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Free Overnight Shipping',
        serviceCode: 'auto',
        selectionLabel: 'Overnight, no charge',
        postageDataSourceId: 9999,
    );

    app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(blindOffer: $tampered));

    expect($seen?->blindOffer?->selectionLabel)->toBe("Shopify's choice")
        ->and($seen->blindOffer->sourceLabel)->toBe('Shopify Shipping')
        ->and($seen->blindOffer->postageDataSourceId)->not->toBe(9999);
});

it('excludes the blind purchase entirely when a special service is hard-required', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    registerBlindSource();

    $signature = SpecialService::create([
        'code' => 'signature_required',
        'name' => 'Signature Required',
        'scope' => 'shipment',
        'category' => 'delivery',
        'requires_value' => false,
        'active' => true,
    ]);
    $package->shipment->shippingMethod->specialServices()->attach($signature->id, ['mode' => 'required']);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    // Visibly, not silently: the packer is told why, and told it in terms of
    // the choice not having been made yet rather than a carrier refusing.
    expect($options->blindPurchaseOffers)->toBeEmpty()
        ->and($options->exclusions)->toHaveCount(1)
        ->and($options->exclusions[0]['carrier'])->toBe('Shopify')
        ->and($options->exclusions[0]['reason'])->toContain('cannot guarantee Signature Required');
});

it('auto-ships a blind purchase when it is the shipping methods sole eligible choice', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $source = registerBlindSource();
    $source->shouldReceive('createShipment')->once()->andReturn(blindShipResponse());

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($package->fresh()->cost)->toBeNull()
        ->and($package->fresh()->activeLabel->carrier_service_id)->toBeNull();
});

it('does not infer a sole blind purchase without a shipping method', function (): void {
    $package = blindPurchasePackage();
    $package->shipment->update(['shipping_method_id' => null]);
    allowBlindPurchase($package);
    $source = registerBlindSource();

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package->fresh(),
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('does not use a blind purchase as an outage fallback for a mixed shipping method', function (): void {
    $package = blindPurchasePackage(withUspsRate: true);
    allowBlindPurchase($package);
    registerUspsRate(price: null);
    $source = registerBlindSource();

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('auto-ships the sole blind choice when a configured direct account sells none of the method services', function (): void {
    // ADR-0006 decision 3: a direct account sells a service only when its
    // integration supports the code. USPS Connect Local is not a single-parcel
    // class the USPS integration prices, so the account is no seller here,
    // and Shopify is the method's only source for this package.
    $package = blindPurchasePackage();
    $account = createUspsAccount();
    $package->shipment->shippingMethod->carrierServices()->attach(
        CarrierService::factory()->for($account->carrier)->create([
            'name' => 'USPS Connect Local',
            'service_code' => 'USPS_CONNECT_LOCAL',
        ])->id,
    );
    allowBlindPurchase($package);
    $source = registerBlindSource();
    $source->shouldReceive('createShipment')->once()->andReturn(blindShipResponse());
    Saloon::fake([]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package->fresh(),
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);

    Saloon::assertNothingSent();
});

it('auto-ships the sole blind choice when another source returns only packaging-incompatible rates', function (): void {
    $package = blindPurchasePackage(withUspsRate: true);
    allowBlindPurchase($package);
    registerUspsRate(
        packagingRequirement: PackagingRequirement::exactly(CarrierPackaging::UspsSmallFlatRateBox),
    );
    $source = registerBlindSource();
    $source->shouldReceive('createShipment')->once()->andReturn(blindShipResponse());

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('excludes a blind purchase selected by an exclude service rule', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $source = registerBlindSource();

    $shopifyService = CarrierService::whereHas('carrier', fn ($query) => $query->where('name', 'Shopify'))->firstOrFail();
    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => ShippingRuleAction::ExcludeService,
        'carrier_service_id' => $shopifyService->id,
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);
    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($options->blindPurchaseOffers)->toBeEmpty()
        ->and($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeFalse()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('applies package weight conditions when excluding an attended blind purchase', function (): void {
    $package = blindPurchasePackage();
    $package->update(['weight' => 20]);
    allowBlindPurchase($package);
    registerBlindSource();

    $shopifyService = CarrierService::whereHas('carrier', fn ($query) => $query->where('name', 'Shopify'))->firstOrFail();
    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => ShippingRuleAction::ExcludeService,
        'carrier_service_id' => $shopifyService->id,
        'conditions' => [
            ['type' => 'weight', 'data' => ['operator' => '>=', 'value' => 16]],
        ],
    ]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package->fresh());

    expect($options->blindPurchaseOffers)->toBeEmpty();
});

it('refuses a forged purchase request for a rule-excluded blind offer', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $source = registerBlindSource();

    $shopifyService = CarrierService::whereHas('carrier', fn ($query) => $query->where('name', 'Shopify'))->firstOrFail();
    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => ShippingRuleAction::ExcludeService,
        'carrier_service_id' => $shopifyService->id,
    ]);

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(blindOffer: shopifyBlindOffer()),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Offer No Longer Available')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('reuses the advertised blind offer snapshot when checking sole-choice automation', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $offerRequests = 0;
    registerBlindSource(offerResolver: function () use (&$offerRequests): Collection {
        $offerRequests++;

        return $offerRequests === 1
            ? collect([shopifyBlindOffer()])
            : collect();
    });

    $rateService = app(ShippingRateService::class);
    $rateService->getShippingRates($package->id);
    $selected = $rateService->soleBlindPurchaseOfferForAutomation($package->id);

    expect($selected?->id())->toBe('Shopify:auto')
        ->and($rateService->getBlindPurchaseOffers())->toHaveCount(1)
        ->and($offerRequests)->toBe(1);
});

it('rejects a sole-offer snapshot belonging to another package', function (): void {
    $firstPackage = blindPurchasePackage();
    allowBlindPurchase($firstPackage);
    registerBlindSource();

    $rateService = app(ShippingRateService::class);
    $rateService->getShippingRates($firstPackage->id);

    $uspsCarrier = Carrier::factory()->usps()->create();
    $uspsService = CarrierService::factory()->uspsGroundAdvantage()->for($uspsCarrier)->create();
    $firstPackage->shipment->shippingMethod->carrierServices()->attach($uspsService);
    registerUspsRate();

    $secondPackage = Package::factory()->for($firstPackage->shipment)->create([
        'status' => PackageStatus::Unshipped,
    ]);
    $rateService->blindPurchaseOffersFor($secondPackage);

    expect(fn () => $rateService->soleBlindPurchaseOfferForAutomation($secondPackage->id))
        ->toThrow(LogicException::class, 'getShippingRates');
});

it('revalidates a sole blind offer at the purchase boundary', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $offerRequests = 0;
    $source = registerBlindSource(offerResolver: function () use (&$offerRequests): Collection {
        $offerRequests++;

        return $offerRequests === 1
            ? collect([shopifyBlindOffer()])
            : collect();
    });

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Offer No Longer Available')
        ->and($offerRequests)->toBe(2)
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    $source->shouldNotHaveReceived('createShipment');
});

it('requires attended selection when a shipping method has multiple blind choices', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);

    $shopifyCarrier = Carrier::where('name', 'Shopify')->sole();
    $priority = CarrierService::factory()->for($shopifyCarrier)->create([
        'name' => "Shopify's USPS Priority Mail",
        'service_code' => 'usps:Priority',
    ]);
    $package->shipment->shippingMethod->carrierServices()->attach($priority);

    $source = registerBlindSource(collect([
        shopifyBlindOffer(),
        new BlindPurchaseOffer(
            source: 'Shopify',
            sourceLabel: 'Shopify Shipping',
            serviceCode: 'usps:Priority',
            selectionLabel: "Shopify's USPS Priority Mail",
        ),
    ]));

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->requiresAttendedSelection)->toBeTrue();

    $source->shouldNotHaveReceived('createShipment');
});

it('represents a shipping rule that pre-selects a blind purchase', function (): void {
    $package = blindPurchasePackage();
    $shopifyService = CarrierService::whereHas('carrier', fn ($query) => $query->where('name', 'Shopify'))->firstOrFail();

    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $shopifyService->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($package->shipment->fresh(), $package);

    expect($result->hasPreSelectedRate())->toBeFalse()
        ->and($result->hasPreSelectedBlindPurchase())->toBeTrue()
        ->and($result->preSelectedBlindPurchaseId)->toBe('Shopify:auto');
});

it('auto-ships a blind purchase selected by a rule on a mixed shipping method', function (): void {
    $package = blindPurchasePackage(withUspsRate: true);
    allowBlindPurchase($package);
    $source = registerBlindSource();
    $source->shouldReceive('createShipment')->once()->andReturn(blindShipResponse());

    $shopifyService = CarrierService::whereHas('carrier', fn ($query) => $query->where('name', 'Shopify'))->firstOrFail();
    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $package->shipment->shipping_method_id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $shopifyService->id,
    ]);

    $result = app(PackageShippingWorkflow::class)->autoShip(
        $package,
        new PackageAutoShippingRequest(cleanupOnFailure: false),
    );

    expect($result->success)->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

/**
 * Shopify's version of the customs-weight problem runs the opposite way to
 * PolyBag's own, and the withholding is the whole remedy — issue `19`.
 */
it('turns a withheld Shopify purchase into a prompt rather than a shipping error', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    registerBlindSource()->shouldReceive('createShipment')->once()
        ->andThrow(new ShopifyDeclaredWeightException(2.29, 0.15));

    $result = app(PackageShippingWorkflow::class)->ship(
        $package,
        new PackageShippingRequest(blindOffer: shopifyBlindOffer()),
    );

    expect($result->success)->toBeFalse()
        ->and($result->requiresDeclaredWeightOverride)->toBeTrue()
        ->and($result->message)->toContain('2.29 lb')
        ->and($result->message)->toContain('0.15 lb')
        // Nothing was bought, and the fix is a product weight in somebody
        // else's catalogue. Dissolving the packed box while they go and correct
        // it would be the worst possible answer.
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

it('asks the packer before insisting, then buys at the scale weight', function (): void {
    $package = blindPurchasePackage();
    allowBlindPurchase($package);

    $source = registerBlindSource();
    $source->shouldReceive('createShipment')->once()
        ->andThrow(new ShopifyDeclaredWeightException(2.29, 0.15))
        ->ordered();
    $source->shouldReceive('createShipment')->once()
        ->andReturnUsing(function (ShipRequest $request): ShipResponse {
            expect($request->overrideDeclaredWeight)->toBeTrue();

            return blindShipResponse();
        })
        ->ordered();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->set('selectedBlindOfferId', 'Shopify:auto')
        ->call('confirmBlindPurchase')
        ->assertDispatched('open-modal', id: 'declared-weight-override')
        ->assertSet('overrideDeclaredWeight', false)
        ->call('confirmDeclaredWeightOverride');

    expect($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('never offers to scale customs weights for a blind purchase', function (): void {
    // The remedy behind that prompt rewrites the customs items PolyBag sends,
    // and a blind purchase sends none: the seller builds the declaration from
    // its own catalogue. Asking would put a confirmation in front of the packer
    // that changes nothing, then fail for the reason they thought they had
    // resolved.
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $package->shipment->update(['country' => 'CA']);
    packHeavyProduct($package);

    registerBlindSource();

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(blindOffer: shopifyBlindOffer(), hasReportPrinter: true),
    );

    expect($result->requiresCustomsWeightOverride)->toBeFalse()
        ->and($result->success)->toBeTrue();
});

it('refuses an international blind purchase when no report printer is configured', function (): void {
    // shopify-shipping-carrier/07 constraint 3 on the path that opened it: an
    // international Shopify purchase returns a separate CUSTOMS_FORM, and a
    // blind offer has no rate, so the gate asks the source about the lane.
    $package = blindPurchasePackage();
    allowBlindPurchase($package);
    $package->shipment->update(['country' => 'CA']);

    $source = registerBlindSource();
    $source->shouldNotReceive('createShipment');

    $result = app(PackageShippingWorkflow::class)->ship(
        $package->fresh(),
        new PackageShippingRequest(blindOffer: shopifyBlindOffer(), hasReportPrinter: false),
    );

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Document Printer Required')
        ->and($result->message)->toContain('Shopify')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);
});

/** A packed item whose product weighs far more than the box was weighed at. */
function packHeavyProduct(Package $package): void
{
    $product = Product::factory()->create(['weight' => 9.0]);

    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $package->shipment_id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    PackageItem::factory()->create([
        'package_id' => $package->id,
        'product_id' => $product->id,
        'shipment_item_id' => $shipmentItem->id,
        'quantity' => 1,
    ]);

    $package->update(['weight' => 0.5]);
}

function blindPurchasePackage(bool $withUspsRate = false): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shopifyCarrier = Carrier::factory()->shopify()->create();
    $shopifyService = CarrierService::factory()->for($shopifyCarrier)->create([
        'name' => "Shopify's choice",
        'service_code' => 'auto',
    ]);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->carrierServices()->attach($shopifyService->id);

    if ($withUspsRate) {
        $uspsService = CarrierService::factory()->uspsGroundAdvantage()->create([
            'carrier_id' => Carrier::factory()->usps()->create()->id,
        ]);
        $shippingMethod->carrierServices()->attach($uspsService->id);
    }

    $shipment = Shipment::factory()->for($shippingMethod)->create([
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);

    return Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);
}

function shopifyBlindOffer(): BlindPurchaseOffer
{
    return new BlindPurchaseOffer(
        source: 'Shopify',
        sourceLabel: 'Shopify Shipping',
        serviceCode: 'auto',
        selectionLabel: "Shopify's choice",
    );
}

/**
 * A stand-in for `ShopifyAdapter` that advertises without quoting. Registered
 * under the same name, so everything routes to it exactly as it would to the
 * real one — which is the point: what is under test is the path, not Shopify.
 */
/**
 * @param  Collection<int, BlindPurchaseOffer>|null  $offers
 */
function registerBlindSource(?Collection $offers = null, ?Closure $offerResolver = null): MockInterface
{
    $source = Mockery::mock(BlindPurchaseSource::class);
    $source->shouldReceive('getCarrierName')->andReturn('Shopify');
    $source->shouldReceive('isConfigured')->andReturnTrue();
    $source->shouldReceive('offerCapability')->andReturn(ServiceCapability::Unguaranteed);
    $source->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    // As Shopify answers: an international purchase returns its commercial
    // invoice as a separate document for the report printer.
    $source->shouldReceive('customsDocumentDelivery')->andReturn(CustomsDocumentDelivery::Separate);
    $blindPurchaseOffers = $source->shouldReceive('blindPurchaseOffers');
    if ($offerResolver) {
        $blindPurchaseOffers->andReturnUsing($offerResolver);
    } else {
        $blindPurchaseOffers->andReturn($offers ?? collect([shopifyBlindOffer()]));
    }
    $source->shouldReceive('createShipment')->andReturnUsing(fn (): ShipResponse => blindShipResponse())->byDefault();

    app(CarrierRegistry::class)->registerInstance('Shopify', $source);

    return $source;
}

function registerUspsRate(
    ?float $price = 8.50,
    ?PackagingRequirement $packagingRequirement = null,
): void {
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('getCarrierName')->andReturn('USPS');
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('serviceCapability')->andReturn(ServiceCapability::Supported);
    $adapter->shouldReceive('offerCapability')->andReturn(ServiceCapability::Supported);
    $adapter->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn($price === null
        ? collect()
        : collect([new RateResponse(
            'USPS',
            'USPS_GROUND_ADVANTAGE',
            'Ground Advantage',
            $price,
            packagingRequirement: $packagingRequirement ?? PackagingRequirement::shipperPackaging(),
        )]));

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);
}

/**
 * What a blind purchase can report back: a tracking number, the carrier the
 * seller turned out to use, and nothing else.
 */
function blindShipResponse(): ShipResponse
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
