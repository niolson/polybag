<?php

use App\Models\Carrier;
use App\Models\Location;
use App\Services\ShipDateService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('advances USPS shipments to the next pickup day after the cutoff hour', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    $shipDate = app(ShipDateService::class)->getShipDate('USPS');

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('keeps non-USPS carriers on the current pickup day after the cutoff hour', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'FedEx']);
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    $shipDate = app(ShipDateService::class)->getShipDate('FedEx');

    expect($shipDate->toDateString())->toBe('2026-04-01');
});

it('advances to the next pickup day after end of day has already been run', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 10:00:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $carrier->locations()->attach($location->id, [
        'pickup_days' => json_encode([1, 2, 3, 4, 5]),
        'last_end_of_day_at' => Carbon::now('America/New_York'),
    ]);

    $shipDate = app(ShipDateService::class)->getShipDate('USPS');

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('creates a carrier-location end-of-day record when one does not exist', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 16:15:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 16:15:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'UPS']);

    app(ShipDateService::class)->endShippingDay('UPS', $location->id);

    $pivotRecord = $carrier->locations()
        ->where('locations.id', $location->id)
        ->firstOrFail()
        ->pivot;

    expect($pivotRecord->last_end_of_day_at)->not->toBeNull()
        ->and(Carbon::parse($pivotRecord->last_end_of_day_at)->setTimezone('America/New_York')->toDateTimeString())->toBe('2026-04-01 16:15:00');
});
