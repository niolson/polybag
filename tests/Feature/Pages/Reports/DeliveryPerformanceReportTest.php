<?php

use App\Enums\Role;
use App\Enums\TrackingStatus;
use App\Filament\Pages\Reports\DeliveryPerformanceReport;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->location = Location::factory()->default()->create(['timezone' => 'America/Los_Angeles', 'country' => 'US']);
    Location::clearDefaultCache();
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
});

afterEach(function (): void {
    Location::clearDefaultCache();
    Carbon::setTestNow();
});

/**
 * A package shipped from the default Location, with its times in UTC.
 *
 * @param  array<string, mixed>  $package
 * @param  array<string, mixed>  $shipment
 */
function packageInTransit(string $shippedAt, ?string $deliveredAt = null, ?string $deliverBy = null, array $package = [], array $shipment = []): Package
{
    return Package::factory()->shipped()->create($package + [
        'location_id' => Location::query()->value('id'),
        'shipment_id' => Shipment::factory()->create($shipment + ['deliver_by' => $deliverBy, 'country' => 'US'])->id,
        'carrier' => 'USPS',
        'shipped_at' => $shippedAt,
        'delivered_at' => $deliveredAt,
        'tracking_status' => $deliveredAt === null ? TrackingStatus::InTransit : TrackingStatus::Delivered,
    ]);
}

function deliveryReport(string $groupBy = 'carrier'): DeliveryPerformanceReport
{
    $test = Livewire::test(DeliveryPerformanceReport::class)
        ->set('groupBy', $groupBy)
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-31']);
    $page = $test->instance();
    assert($page instanceof DeliveryPerformanceReport);

    return $page;
}

/**
 * @return list<array<string, mixed>>
 */
function deliveryRows(DeliveryPerformanceReport $page): array
{
    return (fn (): array => $this->sortedRows(null, null)->values()->all())->call($page);
}

it('measures transit and delivery against deliver-by in the location timezone', function (): void {
    Carbon::setTestNow('2026-11-20 12:00:00');
    // 1, 2 and 5 days in transit.
    packageInTransit('2026-10-05 18:00:00', '2026-10-06 18:00:00', deliverBy: '2026-10-06');
    // 02:00 UTC on the 8th is the evening of the 7th in Los Angeles: on time.
    packageInTransit('2026-10-06 02:00:00', '2026-10-08 02:00:00', deliverBy: '2026-10-07');
    packageInTransit('2026-10-05 18:00:00', '2026-10-10 18:00:00', deliverBy: '2026-10-08');
    // Still out after its deliver-by passed: late.
    packageInTransit('2026-10-05 18:00:00', deliverBy: '2026-10-09');
    // No deliver-by: in transit times only.
    packageInTransit('2026-10-05 18:00:00', '2026-10-06 18:00:00');

    $row = deliveryRows(deliveryReport())[0];

    expect($row['group'])->toBe('USPS')
        ->and($row['packages'])->toBe(5)
        ->and($row['delivered'])->toBe(4)
        ->and($row['delivered_rate'])->toBe(80.0)
        ->and($row['median_days'])->toBe(1.0)
        ->and($row['p90_days'])->toBe(5.0)
        ->and($row['judged'])->toBe(4)
        ->and($row['on_time'])->toBe(2)
        ->and($row['late'])->toBe(2)
        ->and($row['on_time_rate'])->toBe(50.0);
});

it('does not judge a package whose deliver-by is still ahead', function (): void {
    Carbon::setTestNow('2026-10-20 12:00:00');
    packageInTransit('2026-10-19 18:00:00', deliverBy: '2026-10-22');

    $row = deliveryRows(deliveryReport())[0];

    expect($row['judged'])->toBe(0)
        ->and($row['on_time_rate'])->toBeNull();
});

it('counts exceptions, returns and packages stuck in pre-transit as the dashboard does', function (): void {
    Carbon::setTestNow('2026-10-20 12:00:00');
    packageInTransit('2026-10-10 18:00:00', package: ['tracking_status' => TrackingStatus::Exception]);
    packageInTransit('2026-10-10 18:00:00', package: ['tracking_status' => TrackingStatus::Returned]);
    packageInTransit('2026-10-17 18:00:00', package: ['tracking_status' => TrackingStatus::PreTransit]);
    // Pre-transit for only 18 hours is not stuck yet.
    packageInTransit('2026-10-19 18:00:00', package: ['tracking_status' => TrackingStatus::PreTransit]);

    $row = deliveryRows(deliveryReport())[0];

    expect($row['exceptions'])->toBe(1)
        ->and($row['returned'])->toBe(1)
        ->and($row['stuck_pre_transit'])->toBe(1);
});

it('groups by catalog service after the carrier display name', function (): void {
    Carrier::query()->updateOrCreate(['name' => 'USPS'], ['display_name' => 'Postal Service']);
    $ground = CarrierService::factory()->create(['name' => 'Ground Advantage']);
    $mapped = packageInTransit('2026-10-05 18:00:00', '2026-10-07 18:00:00');
    packageInTransit('2026-10-05 18:00:00', '2026-10-07 18:00:00');
    PackageLabel::query()->where('package_id', $mapped->id)->update(['carrier_service_id' => $ground->id]);

    $labels = array_column(deliveryRows(deliveryReport('service')), 'group');
    sort($labels);

    expect($labels)->toBe(['Postal Service (not a catalog service)', 'Postal Service Ground Advantage']);
});

it('splits domestic from international by the origin location country', function (): void {
    packageInTransit('2026-10-05 18:00:00', '2026-10-07 18:00:00');
    packageInTransit('2026-10-05 18:00:00', '2026-10-12 18:00:00', shipment: ['country' => 'CA']);

    $rows = collect(deliveryRows(deliveryReport('destination')))->keyBy('group');

    expect($rows['Domestic']['median_days'])->toBe(2.0)
        ->and($rows['International']['median_days'])->toBe(7.0);
});

it('leaves out packages shipped outside the range', function (): void {
    packageInTransit('2026-09-15 18:00:00', '2026-09-17 18:00:00');

    expect(deliveryRows(deliveryReport()))->toBe([]);
});

it('exports every row', function (): void {
    Carbon::setTestNow('2026-11-20 12:00:00');
    packageInTransit('2026-10-05 18:00:00', '2026-10-07 18:00:00', deliverBy: '2026-10-08');

    ob_start();
    deliveryReport()->exportCsv()->sendContent();
    $lines = array_map(str_getcsv(...), explode("\n", trim((string) ob_get_clean())));

    expect($lines[0])->toBe(['Carrier', 'Packages', 'Delivered', 'Delivered %', 'Median Transit Days', '90th Percentile Transit Days', 'Due by Deliver-By', 'On Time', 'Late', 'On-Time %', 'Exceptions', 'Returned', 'Stuck Pre-Transit'])
        ->and($lines[1])->toBe(['USPS', '1', '1', '100.0', '2.0', '2.0', '1', '1', '0', '100.0', '0', '0', '0']);
});

it('renders the page', function (): void {
    packageInTransit('2026-10-05 18:00:00', '2026-10-07 18:00:00');

    Livewire::test(DeliveryPerformanceReport::class)
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertOk()
        ->assertSee('Median Transit')
        ->assertSee('2.0 days');
});
