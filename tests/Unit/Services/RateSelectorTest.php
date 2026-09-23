<?php

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\OfferRequirements;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\SourceEnvironment;
use App\Models\Client;
use App\Models\ServiceApproval;
use App\Services\RateSelector;
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

    $best = app(RateSelector::class)->selectBest($rates, $deadline, clientId: null);

    expect($best->price)->toBe(5.00);
});

it('selectBest falls back to cheapest overall when all rates are late', function (): void {
    $deadline = Carbon::yesterday()->endOfDay();
    $rates = collect([
        makeRate(10.00, Carbon::today()->toDateString()),
        makeRate(7.00, Carbon::today()->toDateString()),
    ]);

    $best = app(RateSelector::class)->selectBest($rates, $deadline, clientId: null);

    expect($best->price)->toBe(7.00);
});

it('selectBest returns cheapest when no deadline', function (): void {
    $rates = collect([makeRate(10.00), makeRate(5.00), makeRate(8.00)]);

    $best = app(RateSelector::class)->selectBest($rates, null, clientId: null);

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
    expect(app(RateSelector::class)->selectBest(collect([makeUnpricedRate()]), null, clientId: null))->toBeNull()
        ->and(app(RateSelector::class)->selectBest(collect([makeUnpricedRate(), makeRate(9.00)]), null, clientId: null)->price)->toBe(9.00);
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
| Approval — ADR-0003 decision 4
|--------------------------------------------------------------------------
|
| The split is on who is choosing. `classify()` is the attended list and keeps
| everything; `selectBest()` is the unattended one and keeps only what somebody
| approved for this client, in this world.
|
*/

function makeDiscoveredRate(
    float $price,
    string $externalServiceId = 'USPS_GROUND_ADVANTAGE',
    string $externalCarrierId = 'USPS',
    SourceEnvironment $environment = SourceEnvironment::Production,
): RateResponse {
    return new RateResponse(
        carrier: $externalCarrierId,
        serviceCode: $externalServiceId,
        serviceName: 'Ground Advantage',
        price: $price,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            environment: $environment,
            externalCarrierId: $externalCarrierId,
            externalServiceId: $externalServiceId,
        ),
    );
}

function approveDiscoveredService(
    Client $client,
    string $externalServiceId = 'USPS_GROUND_ADVANTAGE',
    string $externalCarrierId = 'USPS',
    SourceEnvironment $environment = SourceEnvironment::Production,
): ServiceApproval {
    return ServiceApproval::factory()->create([
        'source' => 'amazon',
        'environment' => $environment,
        'external_carrier_id' => $externalCarrierId,
        'external_service_id' => $externalServiceId,
        'client_id' => $client->id,
    ]);
}

it('selectBest never returns a discovered service nobody has approved', function (): void {
    $client = Client::where('is_default', true)->firstOrFail();

    $best = app(RateSelector::class)->selectBest(
        collect([makeDiscoveredRate(4.00), makeRate(9.00)]),
        null,
        $client->id,
    );

    expect($best->price)->toBe(9.00)
        ->and($best->observedService)->toBeNull();
});

it('selectBest returns nothing at all when every rate is an unapproved discovered service', function (): void {
    $client = Client::where('is_default', true)->firstOrFail();

    expect(app(RateSelector::class)->selectBest(collect([makeDiscoveredRate(4.00)]), null, $client->id))
        ->toBeNull();
});

it('selectBest returns a discovered service once it is approved, with no other change', function (): void {
    $client = Client::where('is_default', true)->firstOrFail();
    approveDiscoveredService($client);

    $best = app(RateSelector::class)->selectBest(
        collect([makeDiscoveredRate(4.00), makeRate(9.00)]),
        null,
        $client->id,
    );

    expect($best->price)->toBe(4.00)
        ->and($best->observedService?->externalServiceId)->toBe('USPS_GROUND_ADVANTAGE');
});

it('keeps an unapproved discovered service in the attended list', function (): void {
    // The whole point of the split: a packer sees the price and takes
    // responsibility, so classify() is not filtered at all.
    $classified = app(RateSelector::class)->classify(
        collect([makeDiscoveredRate(4.00), makeRate(9.00)]),
        null,
    );

    expect($classified)->toHaveCount(2)
        ->and($classified->first()->rate->price)->toBe(4.00);
});

it('does not let one client spend another client\'s approval', function (): void {
    $approved = Client::factory()->create();
    $other = Client::factory()->create();
    approveDiscoveredService($approved);

    $selector = app(RateSelector::class);
    $rates = collect([makeDiscoveredRate(4.00)]);

    expect($selector->selectBest($rates, null, $approved->id)?->price)->toBe(4.00)
        ->and($selector->selectBest($rates, null, $other->id))->toBeNull();
});

it('denies every discovered service when the package names no client', function (): void {
    approveDiscoveredService(Client::factory()->create());

    expect(app(RateSelector::class)->selectBest(collect([makeDiscoveredRate(4.00)]), null, null))
        ->toBeNull();
});

it('does not let a sandbox approval authorize a production purchase', function (): void {
    $client = Client::where('is_default', true)->firstOrFail();
    approveDiscoveredService($client, environment: SourceEnvironment::Sandbox);

    $selector = app(RateSelector::class);

    expect($selector->selectBest(collect([makeDiscoveredRate(4.00)]), null, $client->id))->toBeNull()
        ->and($selector->selectBest(
            collect([makeDiscoveredRate(4.00, environment: SourceEnvironment::Sandbox)]),
            null,
            $client->id,
        )?->price)->toBe(4.00);
});

it('reports the services it withheld rather than just declining to choose', function (): void {
    $client = Client::where('is_default', true)->firstOrFail();

    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeDiscoveredRate(4.00), makeDiscoveredRate(6.00, 'ONTRAC_GROUND', 'ONTRAC')]),
        null,
        $client->id,
    );

    expect($selection->rate)->toBeNull()
        ->and($selection->withheld)->toHaveCount(2)
        ->and($selection->withheldSummary())->toContain('via amazon')
        ->and($selection->withheldForLog())->toContain([
            'source' => 'amazon',
            'environment' => 'production',
            'carrier' => 'ONTRAC',
            'service' => 'ONTRAC_GROUND',
        ]);
});

it('asks the database nothing when no rate names a discovered service', function (): void {
    DB::enableQueryLog();

    app(RateSelector::class)->selectBest(collect([makeRate(9.00), makeRate(5.00)]), null, 1);

    expect(DB::getQueryLog())->toBeEmpty();

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

it('falls back to the cheapest late rate when the connection requires nothing', function (): void {
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

it('refuses every late rate when the connection requires on-time delivery', function (): void {
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

it('refuses every rate under the on-time requirement when there is no deadline to meet', function (): void {
    $selection = app(RateSelector::class)->selectForAutomation(
        collect([makeRate(5.00, Carbon::today()->toDateString()), makeRate(3.00, null)]),
        null,
        null,
        new OfferRequirements(onTime: true),
    );

    expect($selection->rate)->toBeNull()
        ->and($selection->deadlineMissing)->toBeTrue()
        ->and($selection->late)->toHaveCount(2)
        ->and($selection->attendedAlternativeAvailable)->toBeTrue();
});
