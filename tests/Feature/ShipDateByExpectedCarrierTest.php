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

    it('does not read the Shopify row cutoff', function (): void {
        Carrier::factory()->shopify()->create(['pickup_cutoff_hour' => 10]);
        [$package, $offer] = shopifyChoiceToDate();

        onWednesdayAt('15:00');

        expect(shopifyChoiceShipDate($package, $offer))->toBe('2026-04-01');
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

    it('lists OnTrac and Amazon Shipping, and neither fake row', function (): void {
        Carrier::factory()->create(['name' => 'OnTrac']);
        Carrier::factory()->create(['name' => 'Amazon Shipping']);
        Carrier::factory()->shopify()->create();
        Carrier::factory()->create(['name' => AmazonBuyShippingAdapter::SOURCE_NAME]);

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
    it('dates a Shopify quote by the connection carrier, never by the Shopify row', function (): void {
        $shopifyRow = Carrier::factory()->shopify()->create(['pickup_cutoff_hour' => 10]);
        $auto = CarrierService::factory()->for($shopifyRow)->create(['name' => "Shopify's choice", 'service_code' => ShopifyAdapter::AUTO_SERVICE_CODE]);
        $method = ShippingMethod::factory()->create();
        $method->carrierServices()->attach($auto->id);

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
        $shipDates->shouldNotHaveReceived('getShipDate', [
            Mockery::on(fn (?Carrier $carrier): bool => $carrier?->is($shopifyRow) ?? false),
            Mockery::any(),
        ]);
    });

    it('quotes Amazon with no date, while a direct carrier is quoted for its own', function (): void {
        $method = ShippingMethod::factory()->create();
        $method->carrierServices()->attach([
            CarrierService::factory()->uspsGroundAdvantage()->for($this->usps)->create()->id,
            CarrierService::factory()
                ->for(Carrier::factory()->create(['name' => AmazonBuyShippingAdapter::SOURCE_NAME, 'pickup_cutoff_hour' => 10]))
                ->create(['service_code' => 'AMAZON_BUY_SHIPPING', 'name' => 'Amazon Buy Shipping'])
                ->id,
        ]);
        $connection = DataSource::factory()->unassigned()->offeringOffAmazonShipping()->create();
        CarrierAccountScope::create(['data_source_id' => $connection->id]);

        app(CarrierRegistry::class)->registerInstance(Carrier::USPS, new FakeCarrierAdapter(Carrier::USPS));

        $amazonRequests = [];
        $amazon = Mockery::mock(CarrierAdapterInterface::class.', '.AsyncRateQuoting::class);
        $amazon->shouldReceive('isConfigured')->andReturnTrue();
        $amazon->shouldReceive('offerCapability')->andReturn(ServiceCapability::Supported);
        $amazon->shouldReceive('offerDeclaredValueCap')->andReturnNull();
        $amazon->shouldReceive('prepareRateRequest')->andReturnNull();
        $amazon->shouldReceive('getRates')->andReturnUsing(function (RateRequest $request) use (&$amazonRequests): Collection {
            $amazonRequests[] = $request;

            return collect();
        });
        app(CarrierRegistry::class)->registerInstance(AmazonBuyShippingAdapter::SOURCE_NAME, $amazon);

        // After the fake Amazon row's 10 AM, which nothing may read.
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

        expect($amazonRequests)->toHaveCount(1)
            ->and($amazonRequests[0]->shipDate)->toBeNull()
            ->and($offer->expires_at->timestamp)
            ->toBe(CarbonImmutable::parse('2026-04-01', 'America/New_York')->endOfDay()->timestamp);
    });
});
