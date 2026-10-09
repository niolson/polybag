<?php

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\PackageShippingWorkflow;
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
use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\ClientTaxRegistration;
use App\Models\ExchangeRate;
use App\Models\Location;
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
use Illuminate\Support\Collection;
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
 * @param  (Closure(string): ?string)|null  $offerIdFor  The Offer a carrier's adapter issued itself, as Amazon's do, by carrier name; asked when it quotes
 */
function dutiesFilterMethod(array $carriers = [Carrier::USPS, Carrier::UPS], ?Closure $offerIdFor = null): ShippingMethod
{
    $offerIdFor ??= fn (string $carrier): ?string => null;

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

        // USPS DDP needs an account whose Admin accepted USPS's terms; the
        // acceptance tests below take it away.
        $accountId = $name === Carrier::USPS ? CarrierAccount::factory()->usps()->ddpTermsAccepted()->create()->id : null;

        $adapter = Mockery::mock(DirectCarrierAdapter::class);
        $adapter->shouldReceive('packagingRequirementFor')->andReturn(PackagingRequirement::shipperPackaging());
        $adapter->shouldReceive('getCarrierName')->andReturn($name);
        $adapter->shouldReceive('isConfigured')->andReturnTrue();
        $adapter->shouldReceive('prepareRateRequest')->andReturnNull();
        $adapter->shouldReceive('getRates')->andReturnUsing(fn (): Collection => collect([
            new RateResponse($name, $code, "{$name} International", 30.00, offerId: $offerIdFor($name), carrierAccountId: $accountId, carrierServiceId: $service->id, carrierId: $carrier->id),
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

it('gives an IOSS Shipment to a prepaid-duties country no USPS rate on either term', function (string $country, string $name): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $method = dutiesFilterMethod();

    foreach ([DutiesTerms::Ddu, DutiesTerms::Ddp] as $terms) {
        $package = dutiesFilterPackage($country, $method, ['client_id' => $client->id, 'duties_terms' => $terms]);

        expect(quotedCarriers($package))->toBe([Carrier::UPS])
            ->and(droppedReasons())->toBe(["USPS dropped: {$name} requires prepaid duties, which cannot be combined with an IOSS number (USPS API test)"]);
    }
})->with([['AT', 'Austria'], ['SE', 'Sweden'], ['DE', 'Germany'], ['FR', 'France']]);

it('keeps a USPS DDU rate with an IOSS number where duties are not required, and drops DDP', function (): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $method = dutiesFilterMethod();

    $ddu = dutiesFilterPackage('NL', $method, ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddu]);

    expect(quotedCarriers($ddu))->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS])
        ->and(droppedReasons())->toBe([]);

    $ddp = dutiesFilterPackage('NL', $method, ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddp]);

    expect(quotedCarriers($ddp))->toBe([Carrier::UPS])
        ->and(droppedReasons())->toBe(['USPS dropped: Netherlands cannot take prepaid duties with an IOSS number (USPS API test)']);
});

it('keeps USPS DDP when the consignment is over the IOSS threshold, since no number is declared', function (): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddp]);
    ShipmentItem::query()->where('shipment_id', $package->shipment_id)->update(['value' => 400.0]);

    expect(quotedCarriers($package->fresh()))->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS])
        ->and(droppedReasons())->toBe([]);
});

it('keeps a USPS DDU rate to Austria and Sweden when no registration is declared', function (string $country): void {
    $package = dutiesFilterPackage($country, dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddu]);

    expect(quotedCarriers($package))->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS])
        ->and(droppedReasons())->toBe([]);
})->with(['AT', 'SE']);

it('keeps a USPS DDU rate to Austria and Sweden for a business recipient, but not DDP, which would lose the number', function (string $country, string $name): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $method = dutiesFilterMethod();

    $ddu = dutiesFilterPackage($country, $method, ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddu, 'company' => 'Acme GmbH']);

    expect(quotedCarriers($ddu))->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS])
        ->and(droppedReasons())->toBe([]);

    $ddp = dutiesFilterPackage($country, $method, ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddp, 'company' => 'Acme GmbH']);

    expect(quotedCarriers($ddp))->toBe([Carrier::UPS])
        ->and(droppedReasons())->toBe(["USPS dropped: {$name} cannot take prepaid duties with an IOSS number (USPS API test)"]);
})->with([['AT', 'Austria'], ['SE', 'Sweden']]);

it('gives a business recipient in Germany no USPS rate under an IOSS registration', function (): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $package = dutiesFilterPackage('DE', dutiesFilterMethod(), ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddp, 'company' => 'Acme GmbH']);

    expect(quotedCarriers($package))->toBe([Carrier::UPS])
        ->and(droppedReasons())->toBe(['USPS dropped: Germany requires prepaid duties, which cannot be combined with an IOSS number (USPS API test)']);
});

it('drops a USPS DDP rate to Great Britain under UK VAT, for a consumer and a business alike', function (?string $company): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ukVat()->for($client)->create();
    ExchangeRate::factory()->quoting('GBP', 0.85, now()->subDay()->toDateString())->create();
    $method = dutiesFilterMethod();

    $ddp = dutiesFilterPackage('GB', $method, ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddp, 'company' => $company]);

    expect(quotedCarriers($ddp))->toBe([Carrier::UPS])
        ->and(droppedReasons())->toBe(['USPS dropped: United Kingdom cannot take prepaid duties with a UK VAT number (USPS API test)']);

    $ddu = dutiesFilterPackage('GB', $method, ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddu, 'company' => $company]);

    expect(quotedCarriers($ddu))->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS]);
})->with([[null], ['Acme Ltd']]);

it('retires an Offer when a company name turns the recipient into a business', function (): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddu]);
    app(ShippingRateService::class)->getShippingRates($package->id);
    $offer = ShippingOffer::query()->where('package_id', $package->id)->where('carrier', Carrier::USPS)->firstOrFail();

    $package->shipment->update(['company' => 'Acme GmbH']);

    expect($offer->quoteInputsChangedSince($package->fresh()))->toBeTrue();
});

it('applies the override to an IOSS registration the order carries', function (): void {
    $package = dutiesFilterPackage('AT', dutiesFilterMethod(), [
        'duties_terms' => DutiesTerms::Ddu,
        'seller_tax_regime' => 'ioss',
        'seller_tax_number' => 'IM0400000000',
    ]);

    expect(quotedCarriers($package))->toBe([Carrier::UPS])
        ->and(droppedReasons())->toBe(['USPS dropped: Austria requires prepaid duties, which cannot be combined with an IOSS number (USPS API test)']);
});

it('does not apply the override when the consignment is over the threshold and the registration is withheld', function (): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $package = dutiesFilterPackage('AT', dutiesFilterMethod(), ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddu]);
    ShipmentItem::query()->where('shipment_id', $package->shipment_id)->update(['value' => 400.0]);

    expect(quotedCarriers($package->fresh()))->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS])
        ->and(app(ShippingRateService::class)->getCustomsTerms()?->registrationWithheld())->toBeTrue()
        ->and(droppedReasons())->toBe([]);
});

it('leaves UPS and FedEx rates to Austria and Sweden alone under an IOSS registration', function (string $country): void {
    $client = Client::factory()->create();
    ClientTaxRegistration::factory()->ioss()->for($client)->create();
    $package = dutiesFilterPackage($country, dutiesFilterMethod([Carrier::UPS, Carrier::FEDEX]), ['client_id' => $client->id, 'duties_terms' => DutiesTerms::Ddu]);

    expect(quotedCarriers($package))->toEqualCanonicalizing([Carrier::UPS, Carrier::FEDEX])
        ->and(droppedReasons())->toBe([]);
})->with(['AT', 'SE']);

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

it('drops nothing for a parcel that stays inside the EU', function (): void {
    $berlin = Location::factory()->create(['country' => 'DE', 'city' => 'Berlin', 'postal_code' => '10115', 'state_or_province' => null]);
    $package = dutiesFilterPackage('FR', dutiesFilterMethod());
    $package->update(['location_id' => $berlin->id]);

    expect(quotedCarriers($package->fresh()))->toEqualCanonicalizing([Carrier::USPS, Carrier::UPS])
        ->and(app(ShippingRateService::class)->getDroppedRates())->toBe([])
        ->and(app(ShippingRateService::class)->getCustomsTerms()?->applies)->toBeFalse();
});

it('treats Amazon Shipping as source-decided, through the unresolved refusal too', function (): void {
    $amazonShipping = new RateResponse(Carrier::AMAZON_SHIPPING, 'GROUND', 'Amazon Shipping Ground', 8.0);
    $filter = app(DutiesTermsFilter::class);

    $ddu = $filter->apply(collect([$amazonShipping]), new ResolvedCustomsTerms(applies: true, destinationCountry: 'DE', dutiesTerms: DutiesTerms::Ddu));
    $unresolved = $filter->apply(collect([$amazonShipping]), new ResolvedCustomsTerms(applies: true, destinationCountry: 'DE'));

    expect($filter->isSourceDecided($amazonShipping))->toBeTrue()
        ->and($ddu['kept']->all())->toBe([$amazonShipping])
        ->and($unresolved['kept']->all())->toBe([$amazonShipping])
        ->and($unresolved['droppedRates'])->toBe([]);
});

it('makes an Offer a source issued for a dropped rate unredeemable', function (): void {
    $issuedId = null;
    $method = dutiesFilterMethod(offerIdFor: function (string $carrier) use (&$issuedId): ?string {
        return $carrier === Carrier::USPS ? $issuedId : null;
    });
    $package = dutiesFilterPackage('DE', $method, ['duties_terms' => DutiesTerms::Ddu]);
    $issued = ShippingOffer::factory()->direct()->create(['package_id' => $package->id, 'carrier' => Carrier::USPS]);
    $issuedId = $issued->public_id;

    expect(quotedCarriers($package))->toBe([Carrier::UPS]);

    $redemption = app(OfferStore::class)->redeem($package->fresh(), $issued->public_id);

    expect($redemption->rejection)->toBe(OfferRejection::Expired)
        ->and($issued->fresh()->isConsumed())->toBeFalse();
});

it('names the carrier by its operator label', function (): void {
    $method = dutiesFilterMethod();
    Carrier::query()->where('name', Carrier::USPS)->update(['display_name' => 'Postal Service']);
    $package = dutiesFilterPackage('DE', $method, ['duties_terms' => DutiesTerms::Ddu]);

    quotedCarriers($package);

    expect(app(ShippingRateService::class)->getDroppedRates()[0]->carrier)->toBe('Postal Service')
        ->and(droppedReasons())->toBe(['Postal Service dropped: Germany requires prepaid duties (IMM)']);
});

it('says only direct rates are gone when a source-decided rate remains', function (): void {
    $package = dutiesFilterPackage('FR', dutiesFilterMethod([Carrier::USPS, Carrier::AMAZON_SHIPPING]));

    expect(quotedCarriers($package))->toBe([Carrier::AMAZON_SHIPPING])
        ->and(droppedReasons()[0])->toStartWith('No direct rates: no duties terms are set for France')
        ->and(app(ShippingRateService::class)->allRatesDroppedForCustomsTerms())->toBeFalse();
});

it('keeps the shipping-method hint when no source answered at all', function (): void {
    $package = dutiesFilterPackage('FR', dutiesFilterMethod());
    app(CarrierRegistry::class)->reset();

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSet('rateOptions', [])
        ->assertSet('allRatesDroppedForCustomsTerms', false)
        ->assertSee('No rates: no duties terms are set for France')
        ->assertDontSee('No rate fits the customs terms of this shipment')
        ->assertSee('Check the shipping method configuration');
});

it('does not serve stale rates on the Ship page after a client policy edit', function (): void {
    $client = Client::factory()->withDutiesPolicy(['EU' => 'ddu'])->create();
    $package = dutiesFilterPackage('DE', dutiesFilterMethod(), ['client_id' => $client->id]);

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('USPS dropped: Germany requires prepaid duties (IMM)');

    $client->update(['duties_policy' => ['EU' => 'ddp']]);

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertDontSee('USPS dropped')
        ->assertSee('USPS International');
});

it('keeps an Offer redeemable when the order day\'s rate is fetched after it was quoted', function (): void {
    ExchangeRate::query()->delete();
    ExchangeRate::factory()->quoting('USD', 1.25, '2026-10-07')->create();
    // DDU: USPS cannot carry an IOSS number on DDP, so it would have no Offer.
    $client = Client::factory()->withDutiesPolicy([Client::DUTIES_POLICY_EU => DutiesTerms::Ddu->value])->withIossRegistration()->create();
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['client_id' => $client->id]);
    // $160 of goods, ordered at 16:30 in Frankfurt: after the ECB published
    // the 8th's rates, before PolyBag fetched them.
    $package->packageItems()->first()->shipmentItem->update(['value' => 160.0]);
    $package->shipment->forceFill(['created_at' => CarbonImmutable::parse('2026-10-08 16:30', 'Europe/Berlin')->utc()])->save();
    $package = $package->fresh();

    app(ShippingRateService::class)->getShippingRates($package->id);
    $quoted = RateRequest::fromPackage($package->fresh());
    $offer = ShippingOffer::query()->where('package_id', $package->id)->where('carrier', Carrier::USPS)->firstOrFail();

    // At the 8th's 1.00 the parcel would be €160, over IOSS's €150.
    ExchangeRate::factory()->quoting('USD', 1.00, '2026-10-08')->create();
    $later = RateRequest::fromPackage($package->fresh());

    expect($quoted->customsTerms?->convertedValue?->amount)->toBe(128.0)
        ->and($later->customsTerms?->convertedValue?->amount)->toBe(128.0)
        ->and($later->customsTerms?->registration)->not->toBeNull()
        ->and($later->fingerprint())->toBe($quoted->fingerprint())
        ->and(app(OfferStore::class)->inspect($package->fresh(), $offer->public_id)->rejection)->toBeNull();
});

it('drops a USPS DDP rate on an account that has not accepted the terms, and says why', function (): void {
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddp]);
    CarrierAccount::query()->update(['ddp_terms_accepted_at' => null, 'ddp_terms_accepted_by' => null]);

    expect(quotedCarriers($package))->toBe(['UPS'])
        ->and(droppedReasons())->toBe(['USPS DDP: account terms not accepted']);

    $dropped = app(ShippingRateService::class)->getDroppedRates()[0];

    expect($dropped->carrier)->toBe('USPS')
        ->and($dropped->fixUrl)->toBe(CarrierAccountResource::getUrl('edit', ['record' => CarrierAccount::query()->first()]));
});

it('keeps a USPS DDU rate on an account that has not accepted the terms', function (): void {
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddu]);
    CarrierAccount::query()->update(['ddp_terms_accepted_at' => null, 'ddp_terms_accepted_by' => null]);

    expect(quotedCarriers($package))->toBe(['USPS', 'UPS'])
        ->and(droppedReasons())->toBe([]);
});

it('keeps a UPS DDP rate whatever the USPS account accepted', function (): void {
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddp]);
    CarrierAccount::query()->update(['ddp_terms_accepted_at' => null, 'ddp_terms_accepted_by' => null]);

    expect(quotedCarriers($package))->toContain('UPS');
});

it('keeps the support reason when a USPS rate is dropped for the country, not the terms', function (): void {
    $package = dutiesFilterPackage('PL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddp]);
    CarrierAccount::query()->update(['ddp_terms_accepted_at' => null, 'ddp_terms_accepted_by' => null]);

    quotedCarriers($package);

    expect(droppedReasons())->toHaveCount(1)
        ->and(droppedReasons()[0])->toContain('Poland')
        ->and(droppedReasons()[0])->not->toContain('terms not accepted');
});

it('marks a USPS DDP rate on the Ship page as charged to the account at purchase', function (): void {
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddp]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);
    $usps = collect($options->rateOptions)->firstWhere('carrier', 'USPS');
    $ups = collect($options->rateOptions)->firstWhere('carrier', 'UPS');

    expect($usps['dutiesCharge'])->toBe('DDP: duties charged to the account at purchase')
        ->and($ups)->not->toHaveKey('dutiesCharge');

    Livewire::test(Ship::class, ['package_id' => $package->id])
        ->assertSee('DDP: duties charged to the account at purchase');
});

it('does not mark a USPS DDU rate', function (): void {
    $package = dutiesFilterPackage('NL', dutiesFilterMethod(), ['duties_terms' => DutiesTerms::Ddu]);

    $options = app(PackageShippingWorkflow::class)->prepareRates($package);

    expect(collect($options->rateOptions)->firstWhere('carrier', 'USPS'))->not->toHaveKey('dutiesCharge');
});
