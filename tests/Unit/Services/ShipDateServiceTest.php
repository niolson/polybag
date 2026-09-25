<?php

use App\Models\Carrier;
use App\Models\Location;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\ShipDateService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CarrierSeeder;

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('advances USPS shipments to the next pickup day after the cutoff hour', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    $shipDate = app(ShipDateService::class)->getShipDate($carrier);

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('keeps non-USPS carriers on the current pickup day after the cutoff hour', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'FedEx']);
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    $shipDate = app(ShipDateService::class)->getShipDate($carrier);

    expect($shipDate->toDateString())->toBe('2026-04-01');
});

it('advances to the next pickup day after end of day has already been run', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 10:00:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, [
        'pickup_days' => json_encode([1, 2, 3, 4, 5]),
        'last_end_of_day_at' => Carbon::now('America/New_York'),
    ]);

    $shipDate = app(ShipDateService::class)->getShipDate($carrier);

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('creates a carrier-location end-of-day record when one does not exist', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 16:15:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 16:15:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'UPS']);

    app(ShipDateService::class)->endShippingDay($carrier, $location->id);

    $pivotRecord = $carrier->locations()
        ->where('locations.id', $location->id)
        ->firstOrFail()
        ->pivot;

    expect($pivotRecord->last_end_of_day_at)->not->toBeNull()
        ->and(Carbon::parse($pivotRecord->last_end_of_day_at)->setTimezone('America/New_York')->toDateTimeString())->toBe('2026-04-01 16:15:00');
});

it('reads pickup days from the carrier row it is handed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 10:00:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'UPS']);
    // Wednesday (3) is deliberately not a pickup day for this carrier.
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 4, 5])]);

    $shipDate = app(ShipDateService::class)->getShipDate($carrier);

    expect($shipDate->toDateString())->toBe('2026-04-02')
        ->and(app(ShipDateService::class)->getPickupDays($carrier))->toBe([1, 2, 4, 5]);
});

it('ends the shipping day for the carrier row it is handed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 16:15:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 16:15:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'FedEx']);

    app(ShipDateService::class)->endShippingDay($carrier, $location->id);

    expect(app(ShipDateService::class)->getShipDate($carrier, $location->id)->toDateString())->toBe('2026-04-02');
});

it('keeps the cutoff with the carrier identity when the carrier is renamed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    // An operator relabels the carrier in the admin. The row — and so the
    // identity an offer stored — is unchanged.
    $carrier->update(['display_name' => 'United States Postal Service']);

    $shipDate = app(ShipDateService::class)->getShipDate($carrier->fresh());

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('no longer reads a cutoff from the Shopify row', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    Location::getDefault();
    (new CarrierSeeder)->run();

    // Nothing is dated by the row any more: a Shopify label is dated by the
    // carrier its connection names (`carrier-catalog-reset/08`).
    expect(Carrier::query()->where('name', ShopifyAdapter::CARRIER_NAME)->value('pickup_cutoff_hour'))->toBeNull();
});

it('leaves a carrier with no row on the current pickup day', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    Location::getDefault();

    $shipDate = app(ShipDateService::class)->getShipDate(null);

    expect($shipDate->toDateString())->toBe('2026-04-01');
});

it('picks up Monday through Friday at a location nobody has configured', function (): void {
    // Saturday. No `carrier_location` row exists for any carrier on a fresh
    // install, so this is the policy every carrier actually runs on rather than
    // an untaken branch — ADR-0002, 2026-09-04 amendment.
    Carbon::setTestNow(Carbon::parse('2026-04-04 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 10:00:00', 'America/New_York'));

    $carrier = Carrier::factory()->usps()->create();

    expect(app(ShipDateService::class)->getShipDate($carrier)->toDateString())->toBe('2026-04-06');
});

it('falls back to the default when a carrier is configured with no pickup days at all', function (): void {
    // Saturday again. An empty set used to leave `getNextPickupDay()` no day to
    // find, sending it to its tomorrow fallback and dating the label for Sunday.
    Carbon::setTestNow(Carbon::parse('2026-04-04 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 10:00:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([])]);

    expect(app(ShipDateService::class)->getShipDate($carrier)->toDateString())->toBe('2026-04-06');
});

it('keeps a Saturday pickup an operator configured for this location', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-04 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 10:00:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5, 6])]);

    expect(app(ShipDateService::class)->getShipDate($carrier)->toDateString())->toBe('2026-04-04');
});

it('returns a carrier whose pickup days were removed to the default, not to no pickups at all', function (): void {
    // Saturday. Deleting the row is not a way to say "this carrier never collects
    // here" — there is no such state, because a package on that carrier still
    // needs a ship date. Removal resets to the default, which is what the pickup
    // days form tells the operator.
    Carbon::setTestNow(Carbon::parse('2026-04-04 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 10:00:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5, 6])]);

    $service = app(ShipDateService::class);

    expect($service->getShipDate($carrier)->toDateString())->toBe('2026-04-04');

    $carrier->locations()->detach($location->id);

    expect($service->getShipDate($carrier)->toDateString())->toBe('2026-04-06');
});
