<?php

use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Support\Facades\DB;

/**
 * `capacity-audit/03`: a Database source reads its shipments query once into a
 * temporary file, then hands the import a page at a time, so the run holds one page
 * of rows however many the query returns.
 */
beforeEach(function (): void {
    $this->dataSource = DataSource::factory()->create();

    DB::statement('CREATE TABLE erp_orders (id TEXT, first_name TEXT, address1 TEXT, city TEXT, state TEXT, zip TEXT, country TEXT, notes TEXT)');
});

function erpOrders(int $count, int $from = 1): void
{
    foreach (array_chunk(range($from, $from + $count - 1), 200) as $chunk) {
        DB::table('erp_orders')->insert(array_map(fn (int $n): array => [
            'id' => "SO-{$n}",
            'first_name' => "Person {$n}",
            'address1' => '1600 Pennsylvania Avenue NW',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20500',
            'country' => 'US',
            'notes' => str_repeat('note ', 40),
        ], $chunk));
    }
}

function erpSource(DataSource $dataSource): DatabaseSource
{
    return new DatabaseSource(DataSourceFactory::databaseConfigFor([
        'shipments_query' => 'SELECT * FROM erp_orders ORDER BY rowid',
        'field_mapping' => ['shipment' => [
            'id' => 'shipment_reference',
            'first_name' => 'first_name',
            'address1' => 'address1',
            'city' => 'city',
            'state' => 'state_or_province',
            'zip' => 'postal_code',
            'country' => 'country',
        ]],
    ], config('database.default'), $dataSource->id));
}

it('hands the import its rows a page at a time, in query order', function (): void {
    erpOrders(DatabaseSource::PAGE_SIZE * 2 + 10);
    $source = erpSource($this->dataSource);

    $identities = $source->fetchIdentities();
    $pages = iterator_to_array($source->fetchShipmentPages(), false);

    expect($identities)->toHaveCount(DatabaseSource::PAGE_SIZE * 2 + 10)
        ->and($identities->first())->toBe('SO-1')
        ->and(array_map(fn ($page): int => $page->count(), $pages))->toBe([DatabaseSource::PAGE_SIZE, DatabaseSource::PAGE_SIZE, 10])
        ->and($pages[0]->first()['shipment_reference'])->toBe('SO-1')
        ->and($pages[2]->last()['shipment_reference'])->toBe('SO-'.(DatabaseSource::PAGE_SIZE * 2 + 10));
});

it('keeps each value exactly as the source returned it, through the temporary file', function (): void {
    DB::table('erp_orders')->insert(['id' => 'SO-1', 'first_name' => "Zoë \u{1F4E6}", 'address1' => null, 'city' => 'Washington', 'state' => 'DC', 'zip' => '02134', 'country' => 'US', 'notes' => '']);
    $source = erpSource($this->dataSource);

    $source->fetchIdentities();
    $row = iterator_to_array($source->fetchShipmentPages(), false)[0]->sole();

    expect($row['first_name'])->toBe("Zoë \u{1F4E6}")
        ->and($row['address1'])->toBeNull()
        ->and($row['postal_code'])->toBe('02134');
});

it('still returns every mapped row at once for a caller that wants the set', function (): void {
    erpOrders(300);

    expect(erpSource($this->dataSource)->fetchShipments())->toHaveCount(300);
});

it('imports every row of a long result set', function (): void {
    erpOrders(DatabaseSource::PAGE_SIZE + 50);

    $result = ShipmentImportService::forSource(erpSource($this->dataSource), $this->dataSource)->import();

    expect($result->hasErrors())->toBeFalse()
        ->and($result->shipmentsCreated)->toBe(DatabaseSource::PAGE_SIZE + 50)
        ->and(Shipment::count())->toBe(DatabaseSource::PAGE_SIZE + 50);
});

it('holds about as much memory for ten times as many rows', function (): void {
    $peak = function (int $rows): int {
        DB::table('erp_orders')->delete();
        erpOrders($rows);
        $source = erpSource($this->dataSource);

        gc_collect_cycles();
        memory_reset_peak_usage();

        $source->fetchIdentities();

        foreach ($source->fetchShipmentPages() as $page) {
            unset($page);
        }

        return memory_get_peak_usage();
    };

    // Warm what a first read loads once, so neither read below is charged for it.
    $peak(10);

    $small = $peak(500);
    $large = $peak(5000);

    // Holding every mapped row costs about 2.3 KB a row, 10 MB for the extra 4,500;
    // the run holds each row's ID and little else, measured at under 400 bytes.
    expect($large - $small)->toBeLessThan(3 * 1024 * 1024);
});

it('refuses a query that returns more rows than a run takes, before writing anything', function (): void {
    config(['shipment-import.behavior.max_database_rows' => 50]);
    erpOrders(51);

    $result = ShipmentImportService::forSource(erpSource($this->dataSource), $this->dataSource)->import();

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0])->toContain('more than 50 rows')->toContain('limit it to open orders')
        ->and(Shipment::count())->toBe(0);
});

it('takes a query that returns exactly the most rows a run takes', function (): void {
    config(['shipment-import.behavior.max_database_rows' => 50]);
    erpOrders(50);

    $result = ShipmentImportService::forSource(erpSource($this->dataSource), $this->dataSource)->import();

    expect($result->hasErrors())->toBeFalse()
        ->and(Shipment::count())->toBe(50);
});

it('has no limit when the limit is 0', function (): void {
    config(['shipment-import.behavior.max_database_rows' => 0]);
    erpOrders(60);

    expect(erpSource($this->dataSource)->fetchIdentities())->toHaveCount(60);
});
