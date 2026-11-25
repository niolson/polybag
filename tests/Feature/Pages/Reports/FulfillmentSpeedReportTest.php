<?php

use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Pages\Reports\FulfillmentSpeedReport;
use App\Models\Channel;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    Location::factory()->default()->create(['timezone' => 'America/Los_Angeles']);
    Location::clearDefaultCache();
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
});

afterEach(fn () => Location::clearDefaultCache());

/**
 * An imported order and its first package, with the times in UTC.
 *
 * @param  array<string, mixed>  $shipment
 */
function shippedImportedOrder(string $importedAt, string $shippedAt, string $shipDate, ?string $shipBy = null, array $shipment = []): Shipment
{
    $order = Shipment::factory()->create($shipment + [
        'data_source_id' => DataSource::factory(),
        'ship_by' => $shipBy,
        'status' => ShipmentStatus::Shipped,
        'created_at' => $importedAt,
    ]);
    Package::factory()->shipped()->create(['shipment_id' => $order->id, 'shipped_at' => $shippedAt, 'ship_date' => $shipDate]);

    return $order;
}

function fulfillmentReport(string $groupBy = 'week', string $from = '2026-10-01', string $until = '2026-10-31'): FulfillmentSpeedReport
{
    $test = Livewire::test(FulfillmentSpeedReport::class)
        ->set('groupBy', $groupBy)
        ->filterTable('date_range', ['from' => $from, 'until' => $until]);
    $page = $test->instance();
    assert($page instanceof FulfillmentSpeedReport);

    return $page;
}

/**
 * @return list<array<string, mixed>>
 */
function fulfillmentRows(FulfillmentSpeedReport $page): array
{
    return (fn (): array => $this->sortedRows(null, null)->values()->all())->call($page);
}

it('times orders from import to their first package and counts them on time against ship-by', function (): void {
    // Shipped 4, 10 and 30 hours after import, all in the week of October 12.
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-13 20:00:00', '2026-10-13', shipBy: '2026-10-13');
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-14 02:00:00', '2026-10-13', shipBy: '2026-10-14');
    // 18:00 local on the 15th is past a ship-by of the 14th.
    shippedImportedOrder('2026-10-14 19:00:00', '2026-10-16 01:00:00', '2026-10-15', shipBy: '2026-10-14');

    $page = fulfillmentReport();
    $rows = fulfillmentRows($page);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['group'])->toBe('Oct 12–18')
        ->and($rows[0]['orders'])->toBe(3)
        ->and($rows[0]['median_hours'])->toBe(10.0)
        ->and($rows[0]['p90_hours'])->toBe(30.0)
        ->and($rows[0]['with_ship_by'])->toBe(3)
        ->and($rows[0]['on_time'])->toBe(2)
        ->and($rows[0]['late'])->toBe(1)
        ->and(round($rows[0]['on_time_rate'], 1))->toBe(66.7)
        ->and($page->getSummary()['late'])->toBe(1);
});

it('counts an order with no ship-by in the times but not the on-time rate', function (): void {
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-13 18:00:00', '2026-10-13');

    $row = fulfillmentRows(fulfillmentReport())[0];

    expect($row['orders'])->toBe(1)
        ->and($row['with_ship_by'])->toBe(0)
        ->and($row['on_time_rate'])->toBeNull();
});

it('leaves out Manual Ship orders and drafts', function (): void {
    $manual = Shipment::factory()->create(['data_source_id' => null, 'created_at' => '2026-10-13 16:00:00']);
    Package::factory()->shipped()->create(['shipment_id' => $manual->id, 'shipped_at' => '2026-10-13 16:05:00', 'ship_date' => '2026-10-13']);
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-13 18:00:00', '2026-10-13', shipment: ['status' => ShipmentStatus::Draft]);

    expect(fulfillmentRows(fulfillmentReport()))->toBe([]);
});

it('counts an order in the period of its first package, not a later one', function (): void {
    $order = shippedImportedOrder('2026-09-29 16:00:00', '2026-09-30 18:00:00', '2026-09-30');
    Package::factory()->shipped()->create(['shipment_id' => $order->id, 'shipped_at' => '2026-10-02 18:00:00', 'ship_date' => '2026-10-02']);

    expect(fulfillmentRows(fulfillmentReport()))->toBe([])
        ->and(fulfillmentRows(fulfillmentReport('month', '2026-09-01', '2026-09-30'))[0]['orders'])->toBe(1);
});

it('groups by channel', function (): void {
    $web = Channel::factory()->create(['name' => 'Web Store']);
    $market = Channel::factory()->create(['name' => 'Marketplace']);
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-13 18:00:00', '2026-10-13', shipment: ['channel_id' => $web->id]);
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-13 18:00:00', '2026-10-13', shipment: ['channel_id' => $web->id]);
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-13 18:00:00', '2026-10-13', shipment: ['channel_id' => $market->id]);

    $rows = fulfillmentRows(fulfillmentReport('channel'));

    expect(array_map(fn (array $row): array => [$row['group'], $row['orders']], $rows))
        ->toBe([['Web Store', 2], ['Marketplace', 1]]);
});

it('offers the client grouping only in multi-client mode', function (): void {
    expect(fulfillmentReport()->groupings())->not->toHaveKey('client');

    config()->set('features.multi_client_enabled', true);

    expect(fulfillmentReport()->groupings())->toHaveKey('client');
});

it('counts open orders already past their ship-by, and links to them', function (): void {
    Shipment::factory()->create(['status' => ShipmentStatus::Open, 'ship_by' => now()->subDays(2)->toDateString()]);
    Shipment::factory()->create(['status' => ShipmentStatus::Open, 'ship_by' => now()->addDays(2)->toDateString()]);

    $pastShipBy = fulfillmentReport()->getPastShipBy();

    expect($pastShipBy['count'])->toBe(1)
        ->and(urldecode($pastShipBy['url']))->toContain('filters[ship_by_due][value]=overdue');
});

it('exports every row', function (): void {
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-13 20:00:00', '2026-10-13', shipBy: '2026-10-13');

    ob_start();
    fulfillmentReport()->exportCsv()->sendContent();
    $lines = array_map(str_getcsv(...), explode("\n", trim((string) ob_get_clean())));

    expect($lines[0])->toBe(['Period', 'Orders Shipped', 'Median Hours to Ship', '90th Percentile Hours', 'With Ship-By', 'On Time', 'Late', 'On-Time %'])
        ->and($lines[1])->toBe(['Oct 12–18', '1', '4.0', '4.0', '1', '1', '0', '100.0']);
});

it('shows hours under two days and days beyond', function (): void {
    expect(FulfillmentSpeedReport::duration(5.25))->toBe('5.3 h')
        ->and(FulfillmentSpeedReport::duration(72.0))->toBe('3.0 days')
        ->and(FulfillmentSpeedReport::duration(null))->toBe('—');
});

it('renders the page', function (): void {
    shippedImportedOrder('2026-10-13 16:00:00', '2026-10-13 20:00:00', '2026-10-13', shipBy: '2026-10-13');

    Livewire::test(FulfillmentSpeedReport::class)
        ->filterTable('date_range', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertOk()
        ->assertSee('Median Time to Ship')
        ->assertSee('4.0 h');
});
