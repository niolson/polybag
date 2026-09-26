<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\PostageSourceKind;
use App\Enums\ServiceCapability;
use App\Enums\ServiceEvidence;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Enums\UnlistedServices;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\PostageSourcesRelationManager;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\ShippingRulesRelationManager;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingRule;
use App\Models\SourceServiceMapping;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\ShippingRateService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OnceOnlySeeder;
use Database\Seeders\ShippingMethodSeeder;
use Database\Seeders\ShopifyServiceMappingSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * Shopify sells real catalog services — `carrier-catalog-reset/09`.
 *
 * A method lists real services and says, through its source policy rows,
 * whether Shopify may sell them and whether it may choose for itself (`auto`).
 * A Shopify blind offer is an offer of a real service through Shopify.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->admin()->create());

    $this->usps = Carrier::factory()->usps()->create();
    $this->ups = Carrier::factory()->create(['name' => Carrier::UPS]);
    $this->groundAdvantage = CarrierService::factory()->uspsGroundAdvantage()->for($this->usps)->create();
    $this->upsGround = CarrierService::factory()->upsGround()->for($this->ups)->create();

    SourceServiceMapping::map(PostageSourceKind::Shopify, 'usps', 'GroundAdvantage', $this->groundAdvantage->id);
    SourceServiceMapping::map(PostageSourceKind::Shopify, 'ups_shipping', '03', $this->upsGround->id);
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A package on an opted-in client's Shopify order, whose method lists the
 * given services and allows the given sources.
 *
 * @param  list<CarrierService>  $services
 */
function catalogShopifyPackage(array $services, bool $shopify = true, bool $auto = false, bool $direct = true): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach(collect($services)->pluck('id'));

    if (! $direct) {
        $method->postageSources()->delete();
    }

    if ($shopify) {
        ShippingMethodPostageSource::factory()->shopify()->for($method)->create([
            'unlisted_services' => $auto ? UnlistedServices::Any : UnlistedServices::None,
        ]);
    }

    $package = Package::factory()
        ->for(Shipment::factory()->for($method)->create([
            'data_source_id' => $source->id,
            'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
        ]))
        ->create(['weight' => 2.0, 'status' => PackageStatus::Unshipped]);

    allowBlindPurchase($package);

    return $package->fresh();
}

/**
 * The real adapter, deciding what to offer, with only the purchase faked.
 * Returns the adapter and a holder for the request it was asked to buy.
 *
 * @return array{0: MockInterface, 1: ArrayObject<string, ShipRequest>}
 */
function shopifyBuyingFake(): array
{
    /** @var ArrayObject<string, ShipRequest> $bought */
    $bought = new ArrayObject;
    /** @var ShopifyAdapter&MockInterface $adapter */
    $adapter = Mockery::mock(ShopifyAdapter::class)->makePartial();
    $adapter->shouldReceive('createShipment')->andReturnUsing(function (ShipRequest $request) use ($bought): ShipResponse {
        $bought['request'] = $request;

        return new ShipResponse(
            success: true,
            trackingNumber: '9400111899223197428490',
            cost: null,
            carrier: 'USPS',
            service: null,
            requestedService: $request->blindOffer?->isSourceChoice() ? null : $request->blindOffer?->selectionLabel,
            serviceEvidence: ServiceEvidence::Unknown,
            labelData: base64_encode('LABEL-BYTES'),
            labelFormat: 'pdf',
            postageSource: PostageSource::PostageDataSource,
            postageDataSourceId: $request->blindOffer?->postageDataSourceId,
        );
    });

    app(CarrierRegistry::class)->registerInstance(ShopifyAdapter::CARRIER_NAME, $adapter);

    return [$adapter, $bought];
}

/**
 * A direct carrier that quotes one rate, and counts how often it was asked.
 */
function registerCatalogDirectRate(string $carrier, string $serviceCode, string $serviceName, float $price): MockInterface
{
    $adapter = Mockery::mock(DirectCarrierAdapter::class);
    $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
    $adapter->shouldReceive('getCarrierName')->andReturn($carrier);
    $adapter->shouldReceive('isConfigured')->andReturnTrue();
    $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
    $adapter->shouldReceive('serviceCapability')->andReturn(ServiceCapability::Supported);
    $adapter->shouldReceive('offerCapability')->andReturn(ServiceCapability::Supported);
    $adapter->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $adapter->shouldReceive('getRates')->andReturn(collect([new RateResponse(
        $carrier,
        $serviceCode,
        $serviceName,
        $price,
        packagingRequirement: PackagingRequirement::shipperPackaging(),
    )]));

    app(CarrierRegistry::class)->registerInstance($carrier, $adapter);

    return $adapter;
}

describe('rating', function (): void {
    it('offers each listed service Shopify has a mapping for beside the direct rates', function (): void {
        $package = catalogShopifyPackage([$this->groundAdvantage, $this->upsGround]);
        app(CarrierRegistry::class)->registerInstance(ShopifyAdapter::CARRIER_NAME, new ShopifyAdapter);
        registerCatalogDirectRate(Carrier::USPS, 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 6.10);
        registerCatalogDirectRate(Carrier::UPS, '03', 'UPS Ground', 9.40);

        $options = app(PackageShippingWorkflow::class)->prepareRates($package);

        expect(collect($options->rateOptions)->pluck('carrier')->sort()->values()->all())->toBe([Carrier::UPS, Carrier::USPS])
            ->and(collect($options->blindPurchaseOffers)->pluck('id')->all())->toBe(['Shopify:usps:GroundAdvantage', 'Shopify:ups_shipping:03'])
            ->and(collect($options->blindPurchaseOffers)->pluck('carrierServiceId')->all())->toBe([$this->groundAdvantage->id, $this->upsGround->id])
            ->and(collect($options->blindPurchaseOffers)->pluck('selectionLabel')->all())->toBe(['USPS Ground Advantage', 'UPS Ground']);
    });

    it('offers auto only when the method allows it', function (bool $auto, array $expected): void {
        $package = catalogShopifyPackage([$this->groundAdvantage], auto: $auto, direct: false);
        app(CarrierRegistry::class)->registerInstance(ShopifyAdapter::CARRIER_NAME, new ShopifyAdapter);

        $options = app(PackageShippingWorkflow::class)->prepareRates($package);

        expect(collect($options->blindPurchaseOffers)->pluck('id')->all())->toBe($expected);
    })->with([
        'allowed' => [true, ['Shopify:usps:GroundAdvantage', 'Shopify:auto']],
        'not allowed' => [false, ['Shopify:usps:GroundAdvantage']],
    ]);

    it('quotes a method that lists no services when Shopify may choose', function (): void {
        $package = catalogShopifyPackage([], auto: true);
        app(CarrierRegistry::class)->registerInstance(ShopifyAdapter::CARRIER_NAME, new ShopifyAdapter);

        $options = app(PackageShippingWorkflow::class)->prepareRates($package);

        expect($options->rateOptions)->toBe([])
            ->and(collect($options->blindPurchaseOffers)->pluck('id')->all())->toBe(['Shopify:auto']);
    });

    it('asks Shopify nothing on a method without its row', function (): void {
        $package = catalogShopifyPackage([$this->groundAdvantage], shopify: false);
        app(CarrierRegistry::class)->registerInstance(ShopifyAdapter::CARRIER_NAME, new ShopifyAdapter);
        registerCatalogDirectRate(Carrier::USPS, 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 6.10);

        $options = app(PackageShippingWorkflow::class)->prepareRates($package);

        expect($options->rateOptions)->toHaveCount(1)
            ->and($options->blindPurchaseOffers)->toBe([]);
    });

    it('gives a new method its direct row, and no direct rates without one', function (): void {
        $package = catalogShopifyPackage([$this->groundAdvantage], shopify: false);
        $method = $package->shipment->shippingMethod;
        $usps = registerCatalogDirectRate(Carrier::USPS, 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 6.10);

        expect($method->postageSources()->pluck('source_kind')->all())->toBe([PostageSourceKind::Direct]);

        $method->postageSources()->delete();

        expect(app(ShippingRateService::class)->getShippingRates($package->id))->toBeEmpty();
        $usps->shouldNotHaveReceived('getRates');
    });
});

describe('unattended', function (): void {
    it('buys auto on a method that lists nothing and lets Shopify choose', function (): void {
        $package = catalogShopifyPackage([], auto: true);
        [, $bought] = shopifyBuyingFake();

        $result = app(PackageShippingWorkflow::class)->autoShip(
            $package,
            new PackageAutoShippingRequest(cleanupOnFailure: false),
        );

        expect($result->success)->toBeTrue()
            ->and($bought['request']->blindOffer->serviceCode)->toBe('auto')
            ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
    });

    it('buys nothing on a method with auto and a service unless a rule names one', function (): void {
        $package = catalogShopifyPackage([$this->groundAdvantage], auto: true, direct: false);
        [$adapter, $bought] = shopifyBuyingFake();

        $unruled = app(PackageShippingWorkflow::class)->autoShip(
            $package,
            new PackageAutoShippingRequest(cleanupOnFailure: false),
        );

        expect($unruled->success)->toBeFalse()
            ->and($unruled->requiresAttendedSelection)->toBeTrue()
            ->and($bought)->toHaveCount(0);

        ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
            'shipping_method_id' => $package->shipment->shipping_method_id,
            'action' => ShippingRuleAction::UseService,
            'carrier_service_id' => $this->groundAdvantage->id,
        ]);

        $ruled = app(PackageShippingWorkflow::class)->autoShip(
            $package->fresh(),
            new PackageAutoShippingRequest(cleanupOnFailure: false),
        );

        $label = $package->fresh()->activeLabel;

        // The mapped code, and a preference: never a confirmed service, and no
        // catalog service on the Label (ADR-0003 decision 7).
        expect($ruled->success)->toBeTrue()
            ->and($bought['request']->blindOffer->serviceCode)->toBe('usps:GroundAdvantage')
            ->and($label->carrier_service_id)->toBeNull()
            ->and($label->service_evidence)->not->toBe(ServiceEvidence::Confirmed)
            ->and($package->fresh()->requested_service)->toBe('USPS Ground Advantage');
    });
});

describe('the source policy', function (): void {
    it('refuses a direct row that would sell beyond the listed services', function (): void {
        $method = ShippingMethod::factory()->create();

        expect(fn () => $method->postageSources()->where('source_kind', PostageSourceKind::Direct)->sole()
            ->update(['unlisted_services' => UnlistedServices::Any]))
            ->toThrow(DomainException::class);

        expect(fn () => ShippingMethodPostageSource::factory()->direct()->any()->create())
            ->toThrow(DomainException::class);
    });

    it('lets an Admin allow Shopify and its own choice on the method page', function (): void {
        $method = ShippingMethod::factory()->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'source_kind' => PostageSourceKind::Shopify->value,
                'unlisted_services' => true,
            ])
            ->assertHasNoFormErrors();

        expect($method->fresh()->allowsSource(PostageSourceKind::Shopify))->toBeTrue()
            ->and($method->fresh()->allowsUnlistedServices(PostageSourceKind::Shopify))->toBeTrue();
    });

    it('offers a row for every source kind, Amazon Buy Shipping included since 12', function (): void {
        $method = ShippingMethod::factory()->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->assertFormFieldExists('source_kind', fn (Select $field): bool => array_keys($field->getOptions()) === [
                PostageSourceKind::Direct->value,
                PostageSourceKind::Shopify->value,
                PostageSourceKind::Amazon->value,
            ]);
    });

    it('gives the starter methods their direct row on a fresh seed, which runs without model events', function (): void {
        $this->seed(DatabaseSeeder::class);

        $methods = ShippingMethod::query()->with('postageSources')->get();

        expect($methods)->not->toBeEmpty()
            ->and($methods->every(fn (ShippingMethod $method): bool => $method->allowsSource(PostageSourceKind::Direct)))->toBeTrue();

        $standardGround = $methods->firstWhere('name', 'Standard Ground');
        $standardGround->postageSources()->delete();
        $this->seed(DatabaseSeeder::class);

        expect($standardGround->fresh()->allowsSource(PostageSourceKind::Direct))->toBeFalse();
    });

    it('leaves a direct row an Admin deleted deleted when the seeders run again', function (): void {
        $this->seed(ShippingMethodSeeder::class);
        $method = ShippingMethod::where('name', 'Standard Ground')->sole();

        expect($method->allowsSource(PostageSourceKind::Direct))->toBeTrue();

        $method->postageSources()->delete();
        $this->seed(ShippingMethodSeeder::class);

        expect($method->fresh()->allowsSource(PostageSourceKind::Direct))->toBeFalse();
    });

    it('lets a Manager see the rows but not create, change or delete them', function (): void {
        $this->actingAs(User::factory()->manager()->create());
        $method = ShippingMethod::factory()->create();
        $shopify = ShippingMethodPostageSource::factory()->shopify()->for($method)->create();

        Livewire::test(PostageSourcesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->assertCanSeeTableRecords($method->postageSources)
            ->assertActionHidden(TestAction::make(CreateAction::class)->table())
            ->assertActionHidden(TestAction::make(EditAction::class)->table($shopify))
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($shopify));
    });

    it('offers a Shopify rule its own choice only when the method allows it', function (bool $auto): void {
        $method = ShippingMethod::factory()->create();
        ShippingMethodPostageSource::factory()->shopify()->for($method)->create([
            'unlisted_services' => $auto ? UnlistedServices::Any : UnlistedServices::None,
        ]);

        $form = Livewire::test(ShippingRulesRelationManager::class, [
            'ownerRecord' => $method,
            'pageClass' => EditShippingMethod::class,
        ])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->fillForm([
                'action' => ShippingRuleAction::UseService->value,
                'source' => ShippingRuleSource::Shopify->value,
            ]);

        $auto ? $form->assertFormFieldVisible('any_service') : $form->assertFormFieldHidden('any_service');
    })->with([
        'auto allowed' => [true],
        'auto not allowed' => [false],
    ]);
});

describe('the Shopify mappings', function (): void {
    beforeEach(function (): void {
        SourceServiceMapping::query()->delete();
    });

    it('are written by the first sync on a fresh database, and never again', function (): void {
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        $first = SourceServiceMapping::query()->where('source_kind', PostageSourceKind::Shopify)->orderBy('id')->get(['id', 'updated_at']);

        $this->artisan('app:sync-reference-data')->assertSuccessful();

        expect($first)->toHaveCount(21)
            ->and(SourceServiceMapping::query()->where('source_kind', PostageSourceKind::Shopify)->orderBy('id')->get(['id', 'updated_at'])->toArray())->toBe($first->toArray())
            ->and(OnceOnlySeeder::markerFor(ShopifyServiceMappingSeeder::BATCH))->toBe('reference_data.seeded.shopify-mappings-v1');
    });

    it('stay removed once an Admin removes one', function (): void {
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        SourceServiceMapping::query()->forIdentity(PostageSourceKind::Shopify, 'usps', 'MediaMail')->delete();
        $this->artisan('app:sync-reference-data')->assertSuccessful();

        expect(SourceServiceMapping::query()->where('source_kind', PostageSourceKind::Shopify)->count())->toBe(20)
            ->and(SourceServiceMapping::query()->forIdentity(PostageSourceKind::Shopify, 'usps', 'MediaMail')->exists())->toBeFalse();
    });
});
