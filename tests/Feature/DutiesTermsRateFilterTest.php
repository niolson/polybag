<?php

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\DutiesTerms;
use App\Enums\OfferRejection;
use App\Enums\PackageStatus;
use App\Filament\Pages\Settings;
use App\Filament\Pages\Ship;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\ExchangeRate;
use App\Models\Package;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Customs\DutiesSupportTable;
use App\Services\PostageSources\OfferStore;
use App\Services\Shipping\DutiesTermsFilter;
use App\Services\ShippingRateService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * `international-customs-terms/04`: a rate whose carrier cannot ship on the
 * Shipment's duties term is dropped, with the reason on the Ship page
 * (ADR-0008 decision 4), and the resolved terms are part of the Offer
 * fingerprint.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    $this->actingAs(User::factory()->admin()->create());

    ExchangeRate::factory()->quoting('USD', 1.25, now()->subDay()->toDateString())->create();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

/**
 * A shipping method selling one service on each named carrier, each carrier
 * quoting it through a fake direct adapter.
 *
 * @param  list<string>  $carriers
 */
function dutiesFilterMethod(array $carriers = [Carrier::USPS, Carrier::UPS]): ShippingMethod
{
    $method = ShippingMethod::factory()->create();

    foreach ($carriers as $name) {
        $carrier = Carrier::query()->firstOrCreate(['name' => $name], Carrier::factory()->make(['name' => $name, 'active' => true])->getAttributes());
        $carrier->update(['active' => true]);
        $code = strtoupper(str_replace(' ', '_', $name)).'_INTL';
        $service = CarrierService::factory()->create([
            'carrier_id' => $carrier->id,
            'name' => "{$name} International",
            'service_code' => $code,
            'active' => true,
        ]);
        $method->carrierServices()->attach($service->id);

        $adapter = Mockery::mock(DirectCarrierAdapter::class);
        $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
        $adapter->shouldReceive('getCarrierName')->andReturn($name);
        $adapter->shouldReceive('isConfigured')->andReturnTrue();
        $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
        $adapter->shouldReceive('getRates')->andReturn(collect([
            new RateResponse($name, $code, "{$name} International", 30.00, carrierServiceId: $service->id, carrierId: $carrier->id),
        ]));
        $adapter->shouldNotReceive('createShipment');

        app(CarrierRegistry::class)->registerInstance($name, $adapter);
    }

    return $method;
}

/**
 * A packed box for a consumer in the given country, one $40 item.
 *
 * @param  array<string, mixed>  $shipmentAttributes
 */
function dutiesFilterPackage(string $country, ShippingMethod $method, array $shipmentAttributes = []): Package
{
    $shipment = Shipment::factory()->create($shipmentAttributes + [
        'company' => null,
        'city' => 'Example City',
        'state_or_province' => null,
        'postal_code' => '10115',
        'country' => $country,
        'shipping_method_id' => $method->id,
    ]);

    $package = Package::factory()->for($shipment)->create([
        'box_size_id' => BoxSize::factory()->create()->id,
        'weight' => 2.0,
        'status' => PackageStatus::Unshipped,
    ]);

    $product = Product::factory()->create(['weight' => 0.5, 'sku' => 'SKU-1', 'manufacturer_part_number' => 'MPN-1']);
    $shipmentItem = ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'value' => 40.0,
        'transparency' => false,
    ]);
    $package->packageItems()->create([
        'shipment_item_id' => $shipmentItem->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    return $package->fresh();
}

/**
 * @return list<string>
 */
function quotedCarriers(Package $package): array
{
    return app(ShippingRateService::class)->getShippingRates($package->id)->pluck('carrier')->all();
}

function droppedReasons(): array
{
    return array_map(fn ($dropped): string => $dropped->reason, app(ShippingRateService::class)->getDroppedRates());
}

it('gives a DDU Shipment to Germany no USPS rates, and says why', function (): void {
    $package = dutiesFilterPackage('DE', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddu]);

    expect(quotedCarriers($package))->toBe([Carrier::UPS])
        ->and(droppedReasons())->toBe(['USPS dropped: Germany requires prepaid duties (IMM)'])
        ->and(ShippingOffer::query()->where('carrier', Carrier::USPS)->exists())->toBeFalse();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('USPS dropped: Germany requires prepaid duties (IMM)')
        ->assertSee('UPS International');
});

it('gives a DDP Shipment to Poland no USPS rates', function (): void {
    $package = dutiesFilterPackage('PL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddp]);

    expect(quotedCarriers($package))->toBe([Carrier::UPS])
        ->and(droppedReasons())->toBe(['USPS dropped: Poland cannot take prepaid duties (IMM)']);
});

it('keeps USPS rates for a DDP Shipment to the Netherlands', function (): void {
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddp]);

    expect(quotedCarriers($package))->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS])
        ->and(droppedReasons())->toBe([]);
});

it('takes the term from the client when the order has none', function (): void {
    $client = Client::factory()->withDutiesPolicy(['EU' => 'ddp', 'PL' => 'ddu'])->create();
    $method = dutiesFilterMethod();

    expect(quotedCarriers(dutiesFilterPackage('PL', $method, ['client_id' => $client->id])))
        ->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS]);
});

it('explains on the Ship page when every rate was dropped, instead of an empty list', function (): void {
    $package = dutiesFilterPackage('DE', dutiesFilterMethod([Carrier::USPS]), ['duties_terms' => DutiesTerms::Ddu]);

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSet('rateOptions', [])
        ->assertSee('No rate fits the customs terms of this shipment')
        ->assertSee('USPS dropped: Germany requires prepaid duties (IMM)')
        ->assertDontSee('Check the shipping method configuration');
});

it('refuses an unresolved EU Shipment, linking to Settings in single-client mode', function (): void {
    $package = dutiesFilterPackage('FR', dutiesFilterMethod());

    expect(quotedCarriers($package))->toBe([]);

    $dropped = app(ShippingRateService::class)->getDroppedRates();

    expect($dropped)->toHaveCount(1)
        ->and($dropped[0]->carrier)->toBeNull()
        ->and($dropped[0]->reason)->toContain('no duties terms are set for France or the EU')
        ->and($dropped[0]->reason)->toContain('Settings')
        ->and($dropped[0]->fixUrl)->toBe(Settings::getUrl());

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('No rate fits the customs terms of this shipment')
        ->assertSee('Set duties terms in Settings')
        ->assertSeeHtml('href="'.Settings::getUrl().'"');
});

it('refuses an unresolved EU Shipment, linking to the client form in multi-client mode', function (): void {
    Setting::updateOrCreate(['key' => 'multi_client_enabled'], ['value' => true, 'type' => 'boolean', 'group' => 'general']);
    $client = Client::factory()->create(['name' => 'Northwind Goods', 'duties_policy' => null]);
    $package = dutiesFilterPackage('FR', dutiesFilterMethod(), ['client_id' => $client->id]);

    expect(quotedCarriers($package))->toBe([]);

    $dropped = app(ShippingRateService::class)->getDroppedRates();
    $clientUrl = ClientResource::getUrl('edit', ['record' => $client]);

    expect($dropped[0]->reason)->toContain('Northwind Goods has no duties terms for France or the EU')
        ->and($dropped[0]->fixUrl)->toBe($clientUrl);

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('Set duties terms for Northwind Goods')
        ->assertSeeHtml('href="'.$clientUrl.'"');
});

it('passes a source-decided rate untouched, and does not judge a carrier the file does not list', function (): void {
    $terms = new ResolvedCustomsTerms(applies: true, destinationCountry: 'DE', dutiesTerms: DutiesTerms::Ddu);
    $amazon = new RateResponse(Carrier::USPS, 'X', 'USPS via Amazon', 9.0, observedService: new ObservedServiceIdentity('amazon', 'USPS', 'X'));
    $other = new RateResponse('DHL Express', 'P', 'DHL Express Worldwide', 50.0);
    $usps = new RateResponse(Carrier::USPS, 'PMI', 'Priority Mail International', 40.0);

    $result = app(DutiesTermsFilter::class)->apply(collect([$amazon, $other, $usps]), $terms);

    expect($result['kept']->all())->toBe([$amazon, $other])
        ->and($result['dropped'])->toHaveCount(1);

    $unresolved = app(DutiesTermsFilter::class)->apply(
        collect([$amazon, $usps]),
        new ResolvedCustomsTerms(applies: true, destinationCountry: 'DE'),
    );

    expect($unresolved['kept']->all())->toBe([$amazon]);
});

it('applies an entry from its effective date, not before', function (): void {
    $table = json_decode((string) file_get_contents(resource_path('data/customs/duties-support.json')), true);
    $table['carriers']['ups']['countries']['DE'] = [
        'support' => 'ddp_required',
        'source' => 'docs/issues/international-customs-terms/PRD.md',
        'checked' => '2026-10-08',
        'effective_from' => '2026-11-01',
    ];
    $path = sys_get_temp_dir().'/duties-support-'.bin2hex(random_bytes(8)).'.json';
    file_put_contents($path, json_encode($table));
    register_shutdown_function(static fn (): bool => @unlink($path));
    app()->instance(DutiesSupportTable::class, new DutiesSupportTable($path));

    $terms = new ResolvedCustomsTerms(applies: true, destinationCountry: 'DE', dutiesTerms: DutiesTerms::Ddu);
    $ups = collect([new RateResponse(Carrier::UPS, '07', 'UPS Worldwide Express', 60.0)]);
    $filter = app(DutiesTermsFilter::class);

    expect($filter->apply($ups, $terms, CarbonImmutable::parse('2026-10-31'))['kept'])->toHaveCount(1)
        ->and($filter->apply($ups, $terms, CarbonImmutable::parse('2026-11-01'))['kept'])->toHaveCount(0);
});

it('carries the resolved terms on the rate request', function (): void {
    $client = Client::factory()->ddpToEu()->withIossRegistration()->create();
    $package = dutiesFilterPackage('DE', dutiesFilterMethod(), ['client_id' => $client->id]);

    $terms = RateRequest::fromPackage($package)->customsTerms;

    expect($terms?->dutiesTerms)->toBe(DutiesTerms::Ddp)
        ->and($terms?->registration?->number)->toBe('IM0000000001');

    $domestic = dutiesFilterPackage('US', dutiesFilterMethod([Carrier::UPS]), ['postal_code' => '98101', 'state_or_province' => 'WA']);

    expect(RateRequest::fromPackage($domestic)->customsTerms?->applies)->toBeFalse();
});

it('makes an Offer quoted before a duties_terms edit unredeemable', function (): void {
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddp]);
    app(ShippingRateService::class)->getShippingRates($package->id);
    $offer = ShippingOffer::query()->where('package_id', $package->id)->where('carrier', Carrier::USPS)->firstOrFail();

    expect(app(OfferStore::class)->inspect($package, $offer->public_id)->rejection)->toBeNull();

    $package->shipment->update(['duties_terms' => DutiesTerms::Ddu]);

    $redemption = app(OfferStore::class)->redeem($package->fresh(), $offer->public_id);

    expect($redemption->rejection)->toBe(OfferRejection::PackageChanged)
        ->and($offer->fresh()->isConsumed())->toBeFalse();
});

it('retires an Offer when the registration it would declare changes', function (): void {
    $client = Client::factory()->ddpToEu()->create();
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['client_id' => $client->id]);
    app(ShippingRateService::class)->getShippingRates($package->id);
    $offer = ShippingOffer::query()->where('package_id', $package->id)->firstOrFail();

    ClientTaxRegistration::factory()->ioss()->for($client)->create();

    expect($offer->quoteInputsChangedSince($package))->toBeTrue();
});
