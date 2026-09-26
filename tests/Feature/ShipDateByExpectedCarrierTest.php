<?php

use App\Contracts\AsyncRateQuoting;
use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Filament\Pages\EndOfDay;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierAccountScope;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FakeCarrierAdapter;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\ShipDateService;
use App\Services\ShippingRateService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * `carrier-catalog-reset/08`: a purchase is dated by the carrier expected to
 * carry the parcel, and End of Day ends one day per carrier across every
 * source (ADR-0006, guideline 12).
 *
 * Every test runs on a Wednesday in the default location's timezone. USPS has
 * its seeded 8 PM cutoff; UPS has none unless a test gives it one.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    Cache::flush();

    Location::getDefault()->update(['timezone' => 'America/New_York']);

    $this->usps = Carrier::factory()->usps()->create();
    $this->ups = Carrier::factory()->create(['name' => Carrier::UPS]);
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/**
 * Wednesday 2026-04-01 at the given local time.
 */
/**
 * An Amazon adapter that answers nothing and keeps each request it was asked.
 *
 * @param  list<RateRequest>  $requests
 */
function recordingAmazonAdapter(array &$requests): MockInterface
{
    $amazon = Mockery::mock(CarrierAdapterInterface::class.', '.AsyncRateQuoting::class);
    $amazon->shouldReceive('isConfigured')->andReturnTrue();
    $amazon->shouldReceive('offerCapability')->andReturn(ServiceCapability::Supported);
    $amazon->shouldReceive('offerDeclaredValueCap')->andReturnNull();
    $amazon->shouldReceive('prepareRateRequest')->andReturnNull();
    $amazon->shouldReceive('getRates')->andReturnUsing(function (RateRequest $request) use (&$requests): Collection {
        $requests[] = $request;

        return collect();
    });

    return $amazon;
}

function onWednesdayAt(string $time): void
{
    $now = CarbonImmutable::parse("2026-04-01 {$time}", 'America/New_York');

    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);
}

/**
 * A package on a Shopify order, and Shopify's own choice for it.
 *
 * @param  array<string, mixed>  $settings
 * @return array{0: Package, 1: BlindPurchaseOffer}
 */
function shopifyChoiceToDate(array $settings = []): array
{
    $source = createShopifyDataSource($settings);
    $package = Package::factory()
        ->for(Shipment::factory()->create(['data_source_id' => $source->id]))
        ->create(['location_id' => Location::getDefault()->id]);

    return [$package, new BlindPurchaseOffer(
        source: ShopifyAdapter::CARRIER_NAME,
        sourceLabel: 'Shopify Shipping',
        serviceCode: ShopifyAdapter::AUTO_SERVICE_CODE,
        selectionLabel: "Shopify's choice",
        postageDataSourceId: $source->id,
    )];
}

function shopifyChoiceShipDate(Package $package, BlindPurchaseOffer $offer): string
{
    return ShipRequest::fromPackageAndBlindOffer($package, $offer)->shipDate->toDateString();
}

/**
 * The date a purchase of this offer would carry. The rate names a carrier
 * the offer disagrees with, so the offer's stored carrier has to win.
 */
function offerShipDate(ShippingOffer $offer): string
{
    $rate = new RateResponse(
        carrier: 'Somebody Else',
        serviceCode: (string) $offer->service_code,
        serviceName: (string) $offer->service_name,
        price: (float) $offer->price,
        offerId: $offer->public_id,
        carrierId: null,
    );

    return ShipRequest::fromPackageAndRate($offer->package, $rate, offer: $offer)->shipDate->toDateString();
}

/**
 * An Amazon offer naming the given carrier row, or none.
 */
function amazonOfferCarriedBy(?Carrier $carrier, ?CarrierService $service = null): ShippingOffer
{
    return ShippingOffer::factory()->create([
        'package_id' => Package::factory()->create(['location_id' => Location::getDefault()->id])->id,
        'carrier' => $carrier->name ?? 'Poste Italiane',
        'carrier_id' => $carrier?->id,
        'carrier_service_id' => $service?->id,
    ]);
}

describe('Shopify', function (): void {
    it('dates a Shopify blind purchase today before USPS cutoff and the next pickup day after it', function (): void {
        [$package, $offer] = shopifyChoiceToDate();

        onWednesdayAt('15:00');
        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-01');

        onWednesdayAt('20:30');
        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-02');
    });

    it('dates Shopify purchases by UPS policy when the connection points at UPS', function (): void {
        $this->ups->update(['pickup_cutoff_hour' => 17]);
        [$package, $offer] = shopifyChoiceToDate([DataSource::SHIP_DATE_CARRIER_SETTING => $this->ups->id]);

        // After UPS's 5 PM, before USPS's 8 PM.
        onWednesdayAt('18:00');

        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-02');
    });

    it('dates a Shopify purchase requesting UPS Ground by UPS policy, whatever the connection names', function (): void {
        $this->ups->update(['pickup_cutoff_hour' => 17]);
        $ground = CarrierService::factory()->upsGround()->for($this->ups)->create();
        [$package, $auto] = shopifyChoiceToDate();
        $upsGround = new BlindPurchaseOffer(
            source: ShopifyAdapter::CARRIER_NAME,
            sourceLabel: 'Shopify Shipping',
            serviceCode: 'ups_shipping:03',
            selectionLabel: 'UPS Ground',
            postageDataSourceId: $auto->postageDataSourceId,
            carrierServiceId: $ground->id,
            carrierId: $this->ups->id,
        );

        // After UPS's 5 PM, before USPS's 8 PM, which the connection names.
        onWednesdayAt('18:00');

        expect(shopifyChoiceShipDate($package, $upsGround))->toBe('2026-04-02')
            ->and(shopifyChoiceShipDate($package, $auto))->toBe('2026-04-01');
    });

    it('dates by USPS when the chosen carrier has been deleted or deactivated', function (): void {
        $ontrac = Carrier::factory()->create(['name' => 'OnTrac', 'pickup_cutoff_hour' => 12]);
        $gone = Carrier::factory()->create(['name' => 'Gone']);
        $goneId = $gone->id;
        $gone->delete();

        onWednesdayAt('15:00');

        [$package, $offer] = shopifyChoiceToDate([DataSource::SHIP_DATE_CARRIER_SETTING => $ontrac->id]);
        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-02');

        $ontrac->update(['active' => false]);
        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-01');

        [$package, $offer] = shopifyChoiceToDate([DataSource::SHIP_DATE_CARRIER_SETTING => $goneId]);
        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-01');

        onWednesdayAt('20:30');
        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-02');
    });

    it('dates Shopify by USPS pickup days at the location', function (): void {
        [$package, $offer] = shopifyChoiceToDate();
        $this->usps->locations()->attach(Location::getDefault()->id, ['pickup_days' => json_encode([1, 2, 4, 5])]);

        onWednesdayAt('10:00');

        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-02');
    });

    it('saves the carrier Shopify is dated by from the connection form', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $channel = Channel::factory()->create(['active' => true]);
        $source = createShopifyDataSource(['channel_name' => $channel->id]);

        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->assertFormSet(['settings.'.DataSource::SHIP_DATE_CARRIER_SETTING => $this->usps->id])
            ->fillForm(['settings.'.DataSource::SHIP_DATE_CARRIER_SETTING => $this->ups->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($source->fresh()->settings[DataSource::SHIP_DATE_CARRIER_SETTING])->toBe($this->ups->id)
            ->and($source->fresh()->shipDateCarrier()->is($this->ups))->toBeTrue();
    });
});

describe('Amazon', function (): void {
    it('dates an unmapped Amazon offer by the carrier Amazon names', function (): void {
        $ontrac = Carrier::factory()->create(['name' => 'OnTrac', 'pickup_cutoff_hour' => 17]);
        $offer = amazonOfferCarriedBy($ontrac);

        onWednesdayAt('18:00');

        expect($offer->carrier_service_id)->toBeNull()
            ->and(offerShipDate($offer))->toBe('2026-04-02');
    });

    it('gives an Amazon offer whose carrier has no row no cutoff', function (): void {
        $offer = amazonOfferCarriedBy(null);

        onWednesdayAt('23:30');

        expect(offerShipDate($offer))->toBe('2026-04-01');
    });

    it('dates a mapped Amazon offer by its carrier, so USPS for Ground Advantage', function (): void {
        $groundAdvantage = CarrierService::factory()->uspsGroundAdvantage()->for($this->usps)->create();
        $offer = amazonOfferCarriedBy($this->usps, $groundAdvantage);

        onWednesdayAt('20:30');

        expect(offerShipDate($offer))->toBe('2026-04-02');
    });
});

it('keeps the date when the carrier is renamed between quote and purchase', function (): void {
    $ontrac = Carrier::factory()->create(['name' => 'OnTrac', 'pickup_cutoff_hour' => 17]);
    $offer = amazonOfferCarriedBy($ontrac);

    onWednesdayAt('18:00');

    $ontrac->update(['name' => 'OnTrac Final Mile']);

    expect(offerShipDate($offer->fresh()))->toBe('2026-04-02');
});

describe('End of Day', function (): void {
    beforeEach(function (): void {
        onWednesdayAt('10:00');
        $this->actingAs(User::factory()->admin()->create());
    });

    it('lists OnTrac and Amazon Shipping', function (): void {
        Carrier::factory()->create(['name' => 'OnTrac']);
        Carrier::factory()->create(['name' => 'Amazon Shipping']);

        $listed = collect(Livewire::test(EndOfDay::class)->get('carrierSummary'))->pluck('carrier')->sort()->values()->all();

        expect($listed)->toBe(['Amazon Shipping', 'OnTrac', Carrier::UPS, Carrier::USPS]);
    });

    it('moves direct USPS, Amazon USPS and Shopify labels dated as USPS when USPS day ends', function (): void {
        $direct = ShippingOffer::factory()->create([
            'package_id' => Package::factory()->create(['location_id' => Location::getDefault()->id])->id,
            'postage_source' => PostageSource::CarrierAccount,
            'carrier' => Carrier::USPS,
            'carrier_id' => $this->usps->id,
        ]);
        $amazon = amazonOfferCarriedBy($this->usps);
        [$package, $shopify] = shopifyChoiceToDate();

        expect([offerShipDate($direct), offerShipDate($amazon), shopifyChoiceShipDate($package, $shopify)])
            ->toBe(['2026-04-01', '2026-04-01', '2026-04-01']);

        Livewire::test(EndOfDay::class)
            ->call('endShippingDay', $this->usps->id)
            ->assertNotified();

        expect([offerShipDate($direct), offerShipDate($amazon), shopifyChoiceShipDate($package, $shopify)])
            ->toBe(['2026-04-02', '2026-04-02', '2026-04-02']);
    });

    it('counts a Shopify auto label dated by USPS under UPS after UPS day has ended', function (): void {
        // The UPS driver has left and UPS's day is ended, so UPS now dates
        // Thursday. USPS is still open, and dates the next Shopify label
        // Wednesday; Shopify then puts it on UPS.
        onWednesdayAt('16:00');
        app(ShipDateService::class)->endShippingDay($this->ups);

        onWednesdayAt('17:00');
        [$package, $offer] = shopifyChoiceToDate();
        $shipDate = ShipRequest::fromPackageAndBlindOffer($package, $offer)->shipDate;

        Package::factory()->shipped()->create([
            'carrier' => Carrier::UPS,
            'normalized_carrier_id' => $this->ups->id,
            'postage_source' => PostageSource::PostageDataSource,
            'postage_data_source_id' => $package->shipment->data_source_id,
            'ship_date' => $shipDate,
        ]);

        $summary = collect(Livewire::test(EndOfDay::class)->get('carrierSummary'))->keyBy('carrier');

        expect($shipDate->toDateString())->toBe('2026-04-01')
            ->and($summary[Carrier::UPS]['ship_date'])->toBe('Apr 2')
            ->and($summary[Carrier::UPS]['package_count'])->toBe(1)
            ->and($summary[Carrier::USPS]['package_count'])->toBe(0);
    });

    it('counts a carrier labels from its last End of Day', function (): void {
        // Bought after Tuesday's End of Day, so dated Wednesday and still
        // waiting for Wednesday's pickup.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-31 18:00', 'America/New_York'));
        app(ShipDateService::class)->endShippingDay($this->usps);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-31 19:00', 'America/New_York'));
        Package::factory()->shipped()->create(['carrier' => Carrier::USPS, 'shipped_at' => now()]);

        onWednesdayAt('10:00');
        Package::factory()->shipped()->create(['carrier' => Carrier::USPS]);

        $component = Livewire::test(EndOfDay::class);
        $count = fn (): int => collect($component->get('carrierSummary'))->firstWhere('carrier', Carrier::USPS)['package_count'];

        expect($count())->toBe(2);

        $component->call('endShippingDay', $this->usps->id);
        expect($count())->toBe(0);

        onWednesdayAt('11:00');
        Package::factory()->shipped()->create(['carrier' => Carrier::USPS]);
        $component->call('loadData');
        expect($count())->toBe(1);
    });

    it('counts labels dated today before the carrier day has ever been ended', function (): void {
        // Nobody has ever pressed End of Day for USPS. Tuesday 21:00 is after
        // its 8 PM cutoff, so that label is dated Wednesday.
        Package::factory()->shipped()->create([
            'carrier' => Carrier::USPS,
            'shipped_at' => CarbonImmutable::parse('2026-03-31 21:00', 'America/New_York'),
            'ship_date' => '2026-04-01',
        ]);
        Package::factory()->shipped()->create([
            'carrier' => Carrier::USPS,
            'shipped_at' => CarbonImmutable::parse('2026-03-31 15:00', 'America/New_York'),
            'ship_date' => '2026-03-31',
        ]);

        $usps = collect(Livewire::test(EndOfDay::class)->get('carrierSummary'))->firstWhere('carrier', Carrier::USPS);

        expect($usps['package_count'])->toBe(1);
    });

    it('counts weekend labels dated Monday on Monday', function (): void {
        Package::factory()->shipped()->create([
            'carrier' => Carrier::USPS,
            'shipped_at' => CarbonImmutable::parse('2026-04-04 11:00', 'America/New_York'),
            'ship_date' => '2026-04-06',
        ]);

        $now = CarbonImmutable::parse('2026-04-06 10:00', 'America/New_York');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $usps = collect(Livewire::test(EndOfDay::class)->get('carrierSummary'))->firstWhere('carrier', Carrier::USPS);

        expect($usps['package_count'])->toBe(1);
    });

    it('ignores an End of Day older than the last pickup', function (): void {
        // Ended two weeks ago and never since: the labels bought in between
        // went out on earlier pickups and are not today's.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-18 18:00', 'America/New_York'));
        app(ShipDateService::class)->endShippingDay($this->usps);

        onWednesdayAt('10:00');
        Package::factory()->shipped()->create([
            'carrier' => Carrier::USPS,
            'shipped_at' => CarbonImmutable::parse('2026-03-25 11:00', 'America/New_York'),
            'ship_date' => '2026-03-25',
        ]);
        Package::factory()->shipped()->create(['carrier' => Carrier::USPS]);

        $usps = collect(Livewire::test(EndOfDay::class)->get('carrierSummary'))->firstWhere('carrier', Carrier::USPS);

        expect($usps['package_count'])->toBe(1);
    });

    it('counts an Amazon label under the carrier that carries it', function (): void {
        Package::factory()->shipped()->create([
            'carrier' => 'US Postal Service',
            'normalized_carrier_id' => $this->usps->id,
            'postage_source' => PostageSource::PostageDataSource,
            'postage_data_source_id' => DataSource::factory(),
        ]);

        $usps = collect(Livewire::test(EndOfDay::class)->get('carrierSummary'))->firstWhere('carrier', Carrier::USPS);

        expect($usps['package_count'])->toBe(1)
            ->and($usps['unmanifested_count'])->toBe(0);
    });
});

describe('quoting', function (): void {
    it('dates a Shopify quote by the connection carrier', function (): void {
        // Shopify may choose for itself, and the method lists nothing.
        $method = ShippingMethod::factory()->create();
        ShippingMethodPostageSource::factory()->shopify()->any()->for($method)->create();

        $source = createShopifyDataSource(
            [DataSource::SHIP_DATE_CARRIER_SETTING => $this->ups->id],
            ['oauth_access_token' => 'shpat_test_token'],
        );
        $package = Package::factory()
            ->for(Shipment::factory()->for($method)->create([
                'data_source_id' => $source->id,
                'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
            ]))
            ->create(['weight' => 2.0, 'status' => PackageStatus::Unshipped]);
        allowBlindPurchase($package);
        app(CarrierRegistry::class)->registerInstance(ShopifyAdapter::CARRIER_NAME, new ShopifyAdapter);

        $shipDates = Mockery::spy(ShipDateService::class)->makePartial();
        app()->instance(ShipDateService::class, $shipDates);

        $offers = app(ShippingRateService::class)->blindPurchaseOffersFor($package->fresh());

        expect($offers)->not->toBeEmpty();
        $shipDates->shouldHaveReceived('getShipDate', [
            Mockery::on(fn (?Carrier $carrier): bool => $carrier?->is($this->ups) ?? false),
            Mockery::any(),
        ]);
    });

    it('quotes Amazon Shipping sold directly for its own carrier row\'s date', function (): void {
        $amazonShipping = Carrier::seedSystem(Carrier::AMAZON_SHIPPING);
        $amazonShipping->update(['pickup_cutoff_hour' => 10]);
        $method = ShippingMethod::factory()->create();
        $method->carrierServices()->attach([
            CarrierService::factory()->uspsGroundAdvantage()->for($this->usps)->create()->id,
            amazonShippingGround()->id,
        ]);
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        CarrierAccountScope::create(['data_source_id' => $connection->id]);

        app(CarrierRegistry::class)->registerInstance(Carrier::USPS, new FakeCarrierAdapter(Carrier::USPS));
        $requests = [];
        app(CarrierRegistry::class)->registerInstance(Carrier::AMAZON_SHIPPING, recordingAmazonAdapter($requests));

        // After Amazon Shipping's 10 AM cutoff, before USPS's.
        onWednesdayAt('15:00');

        $package = Package::factory()
            ->for(Shipment::factory()->for($method)->create(['postal_code' => '90210']))
            ->create([
                'box_size_id' => BoxSize::factory()->create()->id,
                'weight' => 2.0,
                'height' => 10,
                'width' => 8,
                'length' => 6,
                'status' => PackageStatus::Unshipped,
            ]);

        $rates = app(ShippingRateService::class)->getShippingRates($package->id);
        $offer = ShippingOffer::where('public_id', $rates->firstWhere('carrier', Carrier::USPS)->offerId)->firstOrFail();

        expect($requests)->toHaveCount(1)
            ->and($requests[0]->shipDate?->toDateString())->toBe('2026-04-02')
            ->and($offer->expires_at->timestamp)
            ->toBe(CarbonImmutable::parse('2026-04-01', 'America/New_York')->endOfDay()->timestamp);
    });

    it('quotes Amazon Buy Shipping with no date', function (): void {
        $method = ShippingMethod::factory()->create();
        ShippingMethodPostageSource::factory()->amazon()->for($method)->create();
        $origin = DataSource::factory()->amazon()->create(['active' => true]);

        $requests = [];
        app(CarrierRegistry::class)->registerInstance(AmazonBuyShippingAdapter::SOURCE_NAME, recordingAmazonAdapter($requests));

        $package = Package::factory()
            ->for(Shipment::factory()->for($method)->create([
                'postal_code' => '90210',
                'data_source_id' => $origin->id,
                'metadata' => ['amazon_order_id' => '111-2222222-3333333'],
            ]))
            ->create(['box_size_id' => BoxSize::factory()->create()->id, 'weight' => 2.0, 'status' => PackageStatus::Unshipped]);

        app(ShippingRateService::class)->getShippingRates($package->id);

        expect($requests)->toHaveCount(1)
            ->and($requests[0]->shipDate)->toBeNull();
    });
});
