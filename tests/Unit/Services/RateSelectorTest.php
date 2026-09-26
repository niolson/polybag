<?php

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\OfferRequirements;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PostageSetting;
use App\Enums\PostageSourceKind;
use App\Enums\UnlistedServices;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Services\RateSelector;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function makeRate(float $price, ?string $deliveryDate = null, string $carrier = 'USPS', string $serviceCode = 'GA'): RateResponse
{
    return new RateResponse(
        carrier: $carrier,
        serviceCode: $serviceCode,
        serviceName: 'Ground Advantage',
        price: $price,
        deliveryDate: $deliveryDate,
    );
}

it('classifies all rates as on-time when there is no deadline', function (): void {
    $rates = collect([makeRate(10.00), makeRate(5.00)]);

    $classified = app(RateSelector::class)->classify($rates, null);

    expect($classified)->toHaveCount(2)
        ->and($classified->every(fn (ClassifiedRate $cr): bool => $cr->isOnTime))->toBeTrue();
});

it('sorts on-time rates before late rates', function (): void {
    $deadline = Carbon::tomorrow()->endOfDay();
    $rates = collect([
        makeRate(12.00, Carbon::parse('+10 days')->toDateString()),
        makeRate(8.00, Carbon::today()->toDateString()),
    ]);

    $classified = app(RateSelector::class)->classify($rates, $deadline);

    expect($classified[0]->isOnTime)->toBeTrue()
        ->and($classified[1]->isOnTime)->toBeFalse();
});

it('sorts each group cheapest first', function (): void {
    $deadline = Carbon::tomorrow()->endOfDay();
    $rates = collect([
        makeRate(15.00, Carbon::today()->toDateString()),
        makeRate(8.00, Carbon::today()->toDateString()),
        makeRate(12.00, Carbon::parse('+10 days')->toDateString()),
        makeRate(6.00, Carbon::parse('+10 days')->toDateString()),
    ]);

    $classified = app(RateSelector::class)->classify($rates, $deadline);

    expect($classified[0]->rate->price)->toBe(8.00)
        ->and($classified[1]->rate->price)->toBe(15.00)
        ->and($classified[2]->rate->price)->toBe(6.00)
        ->and($classified[3]->rate->price)->toBe(12.00);
});

it('treats a same-day delivery as on-time even when the deadline is midnight and the delivery commitment has a later time-of-day', function (): void {
    // Shipment::getDeliverByDate() returns a date-only Carbon (midnight), while carrier
    // commitments (e.g. FedEx) can include a time-of-day like 5pm on the deadline day.
    $deadline = Carbon::tomorrow()->startOfDay();

    $classified = app(RateSelector::class)->classify(
        collect([makeRate(5.00, Carbon::tomorrow()->setTime(17, 0)->toIso8601String(), carrier: 'FedEx')]),
        $deadline,
    );

    expect($classified[0]->isOnTime)->toBeTrue();
});

it('treats unknown delivery date as late when deadline exists', function (): void {
    $deadline = Carbon::tomorrow()->endOfDay();

    $classified = app(RateSelector::class)->classify(collect([makeRate(5.00, null)]), $deadline);

    expect($classified[0]->isOnTime)->toBeFalse();
});

it('treats unknown delivery date as on-time when no deadline', function (): void {
    $classified = app(RateSelector::class)->classify(collect([makeRate(5.00, null)]), null);

    expect($classified[0]->isOnTime)->toBeTrue();
});

it('selectBest returns cheapest on-time rate when deadline exists', function (): void {
    $deadline = Carbon::tomorrow()->endOfDay();
    $rates = collect([
        makeRate(8.00, Carbon::today()->toDateString()),
        makeRate(5.00, Carbon::today()->toDateString()),
        makeRate(3.00, Carbon::parse('+10 days')->toDateString()),
    ]);

    $best = app(RateSelector::class)->selectBest($rates, $deadline, method: null);

    expect($best->price)->toBe(5.00);
});

it('selectBest falls back to cheapest overall when all rates are late', function (): void {
    $deadline = Carbon::yesterday()->endOfDay();
    $rates = collect([
        makeRate(10.00, Carbon::today()->toDateString()),
        makeRate(7.00, Carbon::today()->toDateString()),
    ]);

    $best = app(RateSelector::class)->selectBest($rates, $deadline, method: null);

    expect($best->price)->toBe(7.00);
});

it('selectBest returns cheapest when no deadline', function (): void {
    $rates = collect([makeRate(10.00), makeRate(5.00), makeRate(8.00)]);

    $best = app(RateSelector::class)->selectBest($rates, null, method: null);

    expect($best->price)->toBe(5.00);
});

it('sorts a rate priced only at purchase behind every quoted rate', function (): void {
    $rates = collect([
        makeUnpricedRate(),
        makeRate(10.00),
        makeRate(5.00),
    ]);

    $classified = app(RateSelector::class)->classify($rates, null);

    expect($classified->pluck('rate.price')->all())->toBe([5.00, 10.00, 0.0])
        ->and($classified->last()->rate->priceUnknown)->toBeTrue();
});

it('selectBest never buys a rate whose price nobody has seen', function (): void {
    // Attended, an unpriced rate sorts last and a packer may still take it.
    // Unattended there is nobody to take responsibility, so "it was the only
    // thing offered" is a reason to buy nothing at all — ADR-0003 decision 5.
    expect(app(RateSelector::class)->selectBest(collect([makeUnpricedRate()]), null, method: null))->toBeNull()
        ->and(app(RateSelector::class)->selectBest(collect([makeUnpricedRate(), makeRate(9.00)]), null, method: null)->price)->toBe(9.00);
});

function makeUnpricedRate(): RateResponse
{
    return new RateResponse(
        carrier: 'Shopify',
        serviceCode: 'auto',
        serviceName: "Shopify's choice",
        price: 0.0,
        priceUnknown: true,
    );
}

/*
|--------------------------------------------------------------------------
| The shipping method's allowance — carrier-catalog-reset/13
|--------------------------------------------------------------------------
|
| The split is on who is choosing. `classify()` is the attended list and keeps
| everything; `selectForAutomation()` keeps only what the shipment's method
| allows: a row for the rate's source kind, and a service the method lists or
| a row that allows any service.
|
*/

function makeDiscoveredRate(
    float $price,
    string $externalServiceId = 'USPS_GROUND_ADVANTAGE',
    string $externalCarrierId = 'USPS',
    ?int $carrierServiceId = null,
    bool $contentRestricted = false,
): RateResponse {
    return new RateResponse(
        carrier: $externalCarrierId,
        serviceCode: $externalServiceId,
        serviceName: 'Ground Advantage',
        price: $price,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            externalCarrierId: $externalCarrierId,
            externalServiceId: $externalServiceId,
        ),
        carrierServiceId: $carrierServiceId,
        contentRestricted: $contentRestricted,
    );
}

function makeDirectRate(float $price, CarrierService $service): RateResponse
{
    return new RateResponse(
        carrier: 'USPS',
        serviceCode: $service->service_code,
        serviceName: $service->name,
        price: $price,
        carrierServiceId: $service->id,
    );
}

/**
 * A method listing these services, with its `direct` row and, when given, an
 * `amazon` row saying this about unlisted services.
 *
 * @param  list<CarrierService>  $services
 */
function methodAllowing(array $services, ?UnlistedServices $amazon = null): ShippingMethod
{
    $method = ShippingMethod::factory()->create(['name' => 'Ground']);
    $method->carrierServices()->attach(collect($services)->pluck('id'));

    if ($amazon !== null) {
        ShippingMethodPostageSource::factory()->amazon()->create([
            'shipping_method_id' => $method->id,
            'unlisted_services' => $amazon,
        ]);
    }

    return $method->fresh();
}

it('buys an Amazon offer mapped to a listed service under services on this method', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([$ground], UnlistedServices::None);

    $best = app(RateSelector::class)->selectBest(
        collect([makeDiscoveredRate(4.00, carrierServiceId: $ground->id), makeDirectRate(9.00, $ground)]),
        null,
        $method,
    );

    expect($best->price)->toBe(4.00)
        ->and($best->observedService?->externalServiceId)->toBe('USPS_GROUND_ADVANTAGE');
});

it('never buys an offer for a deactivated service or carrier, whatever the method allows', function (string $deactivate, UnlistedServices $amazon): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([$ground], $amazon);

    if ($deactivate === 'service') {
        $ground->update(['active' => false]);
    } else {
        $ground->carrier->update(['active' => false]);
    }

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeDiscoveredRate(4.00, carrierServiceId: $ground->id), makeDirectRate(9.00, $ground)]),
        null,
        $method,
    );

    expect($selection->rate)->toBeNull()
        ->and($selection->deactivated)->toHaveCount(2)
        ->and($selection->notAllowed)->toBeEmpty()
        // The Ship page will not sell it either, so it is no attended alternative.
        ->and($selection->attendedAlternativeAvailable)->toBeFalse();
})->with([
    'service, listed services only' => ['service', UnlistedServices::None],
    'carrier, listed services only' => ['carrier', UnlistedServices::None],
    'service, any service' => ['service', UnlistedServices::Any],
    'carrier, any service' => ['carrier', UnlistedServices::Any],
]);

it('never buys an unmapped offer from a deactivated carrier under any service', function (): void {
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac', 'active' => false]);
    $method = methodAllowing([], UnlistedServices::Any);

    $unmapped = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'Ground',
        price: 3.00,
        observedService: new ObservedServiceIdentity('amazon', 'ONTRAC', 'ONTRAC_MFN_GROUND'),
        carrierId: $onTrac->id,
    );

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([$unmapped, makeDiscoveredRate(5.00, 'UPS_PTP_GND', 'UPS')]),
        null,
        $method,
    );

    expect($selection->rate->observedService->externalServiceId)->toBe('UPS_PTP_GND')
        ->and($selection->deactivatedSummary())->toBe('OnTrac Ground');
});

it('refuses an unmapped or unlisted Amazon offer under services on this method, and names the method', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $priority = CarrierService::factory()->uspsPriority()->create();
    $method = methodAllowing([$ground], UnlistedServices::None);
    $rates = collect([
        makeDiscoveredRate(3.00, 'ONTRAC_MFN_GROUND', 'ONTRAC'),
        makeDiscoveredRate(4.00, 'USPS_PTP_PRI', carrierServiceId: $priority->id),
        makeDirectRate(9.00, $ground),
    ]);

    $selection = app(RateSelector::class)->selectForAutomation($rates, null, $method);

    expect($selection->rate->price)->toBe(9.00)
        ->and($selection->notAllowed)->toHaveCount(2)
        ->and($selection->shippingMethodName)->toBe('Ground')
        ->and($selection->notAllowedSummary())->toContain('via Amazon Buy Shipping')
        ->and($selection->notAllowedForLog())->toContain([
            'source' => 'amazon',
            'carrier' => 'ONTRAC',
            'service' => 'ONTRAC_MFN_GROUND',
            'carrier_service_id' => null,
        ])
        // The Ship page lists every offer: classify() filters nothing.
        ->and(app(RateSelector::class)->classify($rates, null))->toHaveCount(3);
});

it('buys the cheapest Amazon offer under any service, unmapped ones included', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([], UnlistedServices::Any);

    $best = app(RateSelector::class)->selectBest(
        collect([
            makeDiscoveredRate(4.00, carrierServiceId: $ground->id),
            makeDiscoveredRate(3.00, 'DHL_PARCEL_GROUND', 'DHL_ECOMMERCE'),
        ]),
        null,
        $method,
    );

    expect($best->observedService?->externalServiceId)->toBe('DHL_PARCEL_GROUND');
});

it('never buys a content-restricted offer, even under any service', function (): void {
    $method = methodAllowing([], UnlistedServices::Any);

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([
            makeDiscoveredRate(2.00, 'USPS_PTP_BPM', contentRestricted: true),
            makeDiscoveredRate(5.00, 'UPS_PTP_GND', 'UPS'),
        ]),
        null,
        $method,
    );

    expect($selection->rate->observedService->externalServiceId)->toBe('UPS_PTP_GND')
        ->and($selection->contentRestricted)->toHaveCount(1)
        ->and($selection->notAllowed)->toBeEmpty();
});

it('refuses every Amazon offer for a method with no amazon row', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([$ground]);

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeDiscoveredRate(4.00, carrierServiceId: $ground->id)]),
        null,
        $method,
    );

    expect($selection->rate)->toBeNull()
        ->and($selection->notAllowed)->toHaveCount(1)
        ->and($selection->attendedAlternativeAvailable)->toBeTrue();
});

it('refuses a direct rate for a service the method does not list', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $priority = CarrierService::factory()->uspsPriority()->create();
    $method = methodAllowing([$ground], UnlistedServices::Any);

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeDirectRate(3.00, $priority), makeDirectRate(9.00, $ground)]),
        null,
        $method,
    );

    // The `amazon` row's *any service* covers Amazon only.
    expect($selection->rate->price)->toBe(9.00)
        ->and($selection->notAllowed->pluck('carrierServiceId')->all())->toBe([$priority->id]);
});

it('refuses a direct rate when the method has no direct row', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([$ground], UnlistedServices::None);
    $method->postageSources()->where('source_kind', PostageSourceKind::Direct)->delete();

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeDirectRate(3.00, $ground), makeDiscoveredRate(5.00, carrierServiceId: $ground->id)]),
        null,
        $method->fresh(),
    );

    expect($selection->rate->price)->toBe(5.00)
        ->and($selection->notAllowed)->toHaveCount(1);
});

it('buys any direct rate and never Amazon Buy Shipping for a shipment with no method', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeDiscoveredRate(3.00, carrierServiceId: $ground->id), makeRate(9.00)]),
        null,
        null,
    );

    expect($selection->rate->price)->toBe(9.00)
        ->and($selection->notAllowed)->toHaveCount(1)
        ->and($selection->shippingMethodName)->toBeNull();
});

it('buys the same thing in sandbox and in production', function (bool $sandbox): void {
    Setting::updateOrCreate(['key' => 'sandbox_mode'], ['value' => $sandbox ? '1' : '0', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([$ground], UnlistedServices::None);

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([
            makeDiscoveredRate(3.00, 'ONTRAC_MFN_GROUND', 'ONTRAC'),
            makeDiscoveredRate(4.00, carrierServiceId: $ground->id),
        ]),
        null,
        $method,
    );

    expect($selection->rate->price)->toBe(4.00)
        ->and($selection->notAllowed)->toHaveCount(1);
})->with(['sandbox' => [true], 'production' => [false]]);

it('holds a packer-only connection\'s Amazon order offers, even under any service', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([$ground], UnlistedServices::Any);
    $connection = DataSource::factory()->amazon()->sellingPostage(PostageSetting::PackerOnly)->create(['name' => 'Amazon US']);

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeDiscoveredRate(4.00, carrierServiceId: $ground->id), makeDirectRate(9.00, $ground)]),
        null,
        $method,
        channelSource: $connection,
    );

    expect($selection->rate?->price)->toBe(9.00)
        ->and($selection->notAllowed)->toBeEmpty()
        ->and($selection->heldByPostageSetting)->toHaveCount(1)
        ->and($selection->heldByPostageSettingSummary())->toBe('USPS Ground Advantage')
        ->and($selection->postageSettingConnection)->toBe('Amazon US')
        ->and($selection->attendedAlternativeAvailable)->toBeTrue();
});

it('leaves a packer-and-automation connection\'s offers to the method', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([$ground], UnlistedServices::None);
    $connection = DataSource::factory()->amazon()->sellingPostage(PostageSetting::PackerAndAutomation)->create();

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeDiscoveredRate(4.00, carrierServiceId: $ground->id), makeDirectRate(9.00, $ground)]),
        null,
        $method,
        channelSource: $connection,
    );

    expect($selection->rate?->price)->toBe(4.00)
        ->and($selection->heldByPostageSettingAnything())->toBeFalse();
});

it('never holds Amazon Shipping sold to an order from another channel', function (): void {
    $amazonGround = CarrierService::factory()->create(['service_code' => 'std-us-swa-mfn', 'name' => 'Amazon Shipping Ground']);
    $method = methodAllowing([$amazonGround]);
    $connection = DataSource::factory()->amazon()->sellingPostage(PostageSetting::PackerOnly)->create();

    // A direct rate (`carrier-catalog-reset/15`): no observed service.
    $external = new RateResponse(
        carrier: 'Amazon Shipping',
        serviceCode: 'std-us-swa-mfn',
        serviceName: 'Ground',
        price: 4.00,
        carrierServiceId: $amazonGround->id,
    );

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([$external]),
        null,
        $method,
        channelSource: $connection,
    );

    expect($selection->rate?->price)->toBe(4.00)
        ->and($selection->heldByPostageSettingAnything())->toBeFalse();
});

it('asks the database nothing when there is no method', function (): void {
    DB::enableQueryLog();

    app(RateSelector::class)->selectBest(collect([makeRate(9.00), makeDiscoveredRate(5.00)]), null, null);

    expect(DB::getQueryLog())->toBeEmpty();

    DB::disableQueryLog();
});

it('reads the method\'s rows once, not once per rate', function (): void {
    $ground = CarrierService::factory()->uspsGroundAdvantage()->create();
    $method = methodAllowing([$ground], UnlistedServices::None);

    $rates = collect([
        makeDiscoveredRate(3.00, 'ONTRAC_MFN_GROUND', 'ONTRAC'),
        makeDiscoveredRate(4.00, carrierServiceId: $ground->id),
        makeDiscoveredRate(5.00, 'UPS_PTP_GND', 'UPS'),
        makeDirectRate(9.00, $ground),
    ]);

    DB::enableQueryLog();

    app(RateSelector::class)->selectForAutomation($rates, null, $method);

    // The inactive services the rates name, the postage-source rows and the
    // listed services.
    expect(DB::getQueryLog())->toHaveCount(3);

    DB::disableQueryLog();
});

/*
|--------------------------------------------------------------------------
| amazon-buy-shipping/16 — an Amazon connection's offer requirements
|--------------------------------------------------------------------------
*/

/**
 * A rate carrying Amazon's `benefits` block the way the adapter stores it.
 */
function rateWithBenefits(float $price, ?string $deliveryDate, bool $otdrProtected, array $reasonCodes = []): RateResponse
{
    return new RateResponse(
        carrier: 'UPS',
        serviceCode: 'GND',
        serviceName: 'Ground',
        price: $price,
        deliveryDate: $deliveryDate,
        metadata: ['benefits' => [
            'includedBenefits' => $otdrProtected ? ['CLAIMS_PROTECTED', 'OTDR_PROTECTED'] : [],
            'excludedBenefits' => $otdrProtected ? [] : [
                ['benefit' => 'OTDR_PROTECTED', 'reasonCodes' => $reasonCodes],
            ],
        ]],
    );
}

it('falls back to the cheapest late rate when the method requires nothing', function (): void {
    $deadline = Carbon::tomorrow();

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeRate(9.00, Carbon::parse('+5 days')->toDateString()), makeRate(7.00, Carbon::parse('+6 days')->toDateString())]),
        $deadline,
        null,
        new OfferRequirements,
    );

    expect($selection->rate->price)->toBe(7.00)
        ->and($selection->refusedForRequirements())->toBeFalse();
});

it('refuses every late rate when the method excludes late rates', function (): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeRate(9.00, Carbon::parse('+5 days')->toDateString()), makeRate(7.00, null)]),
        Carbon::tomorrow(),
        null,
        new OfferRequirements(onTime: true),
    );

    expect($selection->rate)->toBeNull()
        ->and($selection->late)->toHaveCount(2)
        ->and($selection->unprotected)->toBeEmpty()
        ->and($selection->attendedAlternativeAvailable)->toBeTrue();
});

it('buys the on-time rate over a cheaper late one under every requirement the rate meets', function (bool $onTime, bool $otdrProtection): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([
            rateWithBenefits(4.00, Carbon::parse('+5 days')->toDateString(), otdrProtected: true),
            rateWithBenefits(8.00, Carbon::today()->toDateString(), otdrProtected: true),
        ]),
        Carbon::tomorrow(),
        null,
        new OfferRequirements(onTime: $onTime, otdrProtection: $otdrProtection),
    );

    expect($selection->rate->price)->toBe(8.00);
})->with([
    'neither' => [false, false],
    'on time' => [true, false],
    'protection' => [false, true],
    'both' => [true, true],
]);

it('buys an on-time rate that carries LATE_DELIVERY_RISK when only on-time is required', function (): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([rateWithBenefits(6.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['LATE_DELIVERY_RISK'])]),
        Carbon::tomorrow(),
        null,
        new OfferRequirements(onTime: true),
    );

    expect($selection->rate?->price)->toBe(6.00);
});

it('buys a protected late rate when only protection is required', function (): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([
            rateWithBenefits(5.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['NON_SSA_ORDER']),
            rateWithBenefits(7.00, Carbon::parse('+5 days')->toDateString(), otdrProtected: true),
        ]),
        Carbon::tomorrow(),
        null,
        new OfferRequirements(otdrProtection: true),
    );

    expect($selection->rate->price)->toBe(7.00);
});

it('refuses an unprotected rate, direct carriers included, when protection is required', function (): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([
            makeRate(3.00, Carbon::today()->toDateString()),
            rateWithBenefits(5.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['NON_SSA_ORDER']),
        ]),
        Carbon::tomorrow(),
        null,
        new OfferRequirements(otdrProtection: true),
    );

    expect($selection->rate)->toBeNull()
        ->and($selection->unprotected)->toHaveCount(2)
        ->and($selection->late)->toBeEmpty();
});

it('buys only a rate that is both on time and protected when both are required', function (): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([
            rateWithBenefits(4.00, Carbon::parse('+5 days')->toDateString(), otdrProtected: true),
            rateWithBenefits(5.00, Carbon::today()->toDateString(), otdrProtected: false, reasonCodes: ['NON_AHT_ORDER']),
            rateWithBenefits(9.00, Carbon::today()->toDateString(), otdrProtected: true),
        ]),
        Carbon::tomorrow(),
        null,
        new OfferRequirements(onTime: true, otdrProtection: true),
    );

    expect($selection->rate->price)->toBe(9.00);
});

it('refuses every rate for an order that must have a deadline and has none', function (): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeRate(5.00, Carbon::today()->toDateString()), makeRate(3.00, null)]),
        null,
        null,
        new OfferRequirements(onTime: true, deadlineRequired: true),
    );

    expect($selection->rate)->toBeNull()
        ->and($selection->deadlineMissing)->toBeTrue()
        ->and($selection->late)->toHaveCount(2)
        ->and($selection->attendedAlternativeAvailable)->toBeTrue();
});

it('refuses nothing as late when there is no deadline and the order need not have one', function (): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeRate(5.00, Carbon::today()->toDateString()), makeRate(3.00, null)]),
        null,
        null,
        new OfferRequirements(onTime: true),
    );

    expect($selection->rate->price)->toBe(3.00)
        ->and($selection->deadlineMissing)->toBeFalse()
        ->and($selection->late)->toBeEmpty();
});
