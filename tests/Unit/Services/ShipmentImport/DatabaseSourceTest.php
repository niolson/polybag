<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('runs mark exported query against the external connection', function (): void {
    // Create a temp table on the default connection to simulate the external DB
    DB::statement('CREATE TEMPORARY TABLE external_shipments (id VARCHAR(255), exported VARCHAR(1) DEFAULT "n")');
    DB::table('external_shipments')->insert(['id' => 'ORD-001', 'exported' => 'n']);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipments_table' => 'external_shipments',
        'shipment_items_table' => 'shipment_items',
        'field_mapping' => [],
        'mark_exported' => [
            'enabled' => true,
            'query' => "update external_shipments set exported = 'y' where id = :shipment_reference",
        ],
    ]);

    $source->markExported('ORD-001');

    $row = DB::table('external_shipments')->where('id', 'ORD-001')->first();
    expect($row->exported)->toBe('y');
});

it('does nothing when mark_exported is disabled', function (): void {
    DB::statement('CREATE TEMPORARY TABLE external_shipments2 (id VARCHAR(255), exported VARCHAR(1) DEFAULT "n")');
    DB::table('external_shipments2')->insert(['id' => 'ORD-002', 'exported' => 'n']);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipments_table' => 'external_shipments2',
        'shipment_items_table' => 'shipment_items',
        'field_mapping' => [],
        'mark_exported' => [
            'enabled' => false,
            'query' => "update external_shipments2 set exported = 'y' where id = :shipment_reference",
        ],
    ]);

    $source->markExported('ORD-002');

    $row = DB::table('external_shipments2')->where('id', 'ORD-002')->first();
    expect($row->exported)->toBe('n');
});

it('does nothing when mark_exported config is missing', function (): void {
    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipments_table' => 'shipments',
        'shipment_items_table' => 'shipment_items',
        'field_mapping' => [],
    ]);

    // Should not throw
    $source->markExported('ORD-003');
    expect(true)->toBeTrue();
});

// ── fetchShipments ────────────────────────────────────────────────────────────

it('fetches and maps shipments from a table with equality and whereIn filters', function (): void {
    DB::statement('CREATE TEMPORARY TABLE ext_ship (order_ref VARCHAR(255), status VARCHAR(20), region VARCHAR(20))');
    DB::table('ext_ship')->insert([
        ['order_ref' => 'A-1', 'status' => 'ready', 'region' => 'west'],
        ['order_ref' => 'A-2', 'status' => 'ready', 'region' => 'east'],
        ['order_ref' => 'A-3', 'status' => 'shipped', 'region' => 'west'],
    ]);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipments_table' => 'ext_ship',
        'shipment_items_table' => 'items',
        'field_mapping' => ['shipment' => ['order_ref' => 'source_record_id']],
        'filters' => [
            'status' => 'ready',
            'region' => ['west', 'east'],
        ],
    ]);

    $shipments = $source->fetchShipments();

    expect($shipments)->toHaveCount(2)
        ->and($shipments->pluck('source_record_id')->all())->toEqualCanonicalizing(['A-1', 'A-2']);
});

it('carries over the raw client column value when configured', function (): void {
    DB::statement('CREATE TEMPORARY TABLE ext_ship_client (order_ref VARCHAR(255), brand VARCHAR(50))');
    DB::table('ext_ship_client')->insert(['order_ref' => 'A-1', 'brand' => 'acme']);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipments_table' => 'ext_ship_client',
        'shipment_items_table' => 'items',
        'field_mapping' => ['shipment' => ['order_ref' => 'source_record_id']],
        'client_column' => 'brand',
    ]);

    $shipments = $source->fetchShipments();

    expect($shipments->first()['_client_column_value'])->toBe('acme');
});

it('fetches shipments using a custom query and audits the execution', function (): void {
    DB::statement('CREATE TEMPORARY TABLE ext_ship_q (order_ref VARCHAR(255))');
    DB::table('ext_ship_q')->insert(['order_ref' => 'Q-1']);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'data_source_id' => 12,
        'shipment_items_table' => 'items',
        'field_mapping' => ['shipment' => ['order_ref' => 'source_record_id']],
        'shipments_query' => 'SELECT order_ref FROM ext_ship_q',
    ]);

    $shipments = $source->fetchShipments();

    expect($shipments->first()['source_record_id'])->toBe('Q-1');

    $log = AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->firstOrFail();
    expect($log->metadata['operation'])->toBe('fetch_shipments')
        ->and($log->metadata['status'])->toBe('success');
});

// ── fetchShipmentItems ────────────────────────────────────────────────────────

it('fetches shipment items from the default table by shipment reference', function (): void {
    DB::statement('CREATE TEMPORARY TABLE ext_items (shipment_id VARCHAR(255), sku VARCHAR(50))');
    DB::table('ext_items')->insert([
        ['shipment_id' => 'A-1', 'sku' => 'SKU-1'],
        ['shipment_id' => 'A-1', 'sku' => 'SKU-2'],
        ['shipment_id' => 'A-2', 'sku' => 'SKU-3'],
    ]);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipments_table' => 'ext_ship',
        'shipment_items_table' => 'ext_items',
        'field_mapping' => ['shipment_item' => ['sku' => 'product_sku']],
    ]);

    $items = $source->fetchShipmentItems('A-1');

    expect($items)->toHaveCount(2)
        ->and($items->pluck('product_sku')->all())->toEqualCanonicalizing(['SKU-1', 'SKU-2']);
});

it('fetches shipment items using a custom parameterized query', function (): void {
    DB::statement('CREATE TEMPORARY TABLE ext_items_q (ship_ref VARCHAR(255), sku VARCHAR(50))');
    DB::table('ext_items_q')->insert([
        ['ship_ref' => 'A-1', 'sku' => 'SKU-1'],
        ['ship_ref' => 'A-2', 'sku' => 'SKU-2'],
    ]);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipment_items_table' => 'ext_items_q',
        'field_mapping' => ['shipment_item' => ['sku' => 'product_sku']],
        'shipment_items_query' => 'SELECT sku FROM ext_items_q WHERE ship_ref = :shipment_reference',
    ]);

    $items = $source->fetchShipmentItems('A-1');

    expect($items)->toHaveCount(1)
        ->and($items->first()['product_sku'])->toBe('SKU-1');
});

// ── configuration validation ──────────────────────────────────────────────────

it('validates a healthy connection without throwing', function (): void {
    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipments_table' => 'shipments',
        'shipment_items_table' => 'shipment_items',
        'field_mapping' => [],
    ]);

    $source->validateConfiguration();

    expect(true)->toBeTrue();
});

it('throws when validating configuration with no connection', function (): void {
    $source = new DatabaseSource(['field_mapping' => []]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'Database connection is not configured.');
});

it('throws a friendly error when the import connection cannot be reached', function (): void {
    config(['database.connections.unreachable_import' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'nope',
    ]]);

    $source = new DatabaseSource([
        'connection' => 'unreachable_import',
        'field_mapping' => [],
    ]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'Cannot connect to import database.');
});

// ── export configuration validation ───────────────────────────────────────────

it('throws when validating export config that is disabled', function (): void {
    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'field_mapping' => [],
        'export' => ['enabled' => false, 'query' => 'UPDATE x SET y = 1'],
    ]);

    expect(fn () => $source->validateExportConfiguration())
        ->toThrow(InvalidArgumentException::class, 'Export is not enabled');
});

it('throws when validating export config with no query', function (): void {
    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'field_mapping' => [],
        'export' => ['enabled' => true],
    ]);

    expect(fn () => $source->validateExportConfiguration())
        ->toThrow(InvalidArgumentException::class, 'Export query is not configured');
});

it('validates a healthy export configuration without throwing', function (): void {
    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'field_mapping' => [],
        'export' => ['enabled' => true, 'query' => 'UPDATE x SET y = :tracking_number'],
    ]);

    $source->validateExportConfiguration();

    expect(true)->toBeTrue();
});

it('throws when exporting without a configured query', function (): void {
    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'field_mapping' => [],
    ]);

    expect(fn (): ?string => $source->exportPackage(['tracking_number' => 'TRK1']))
        ->toThrow(InvalidArgumentException::class, 'Export query is not configured');
});

it('exports only the parameters the query references', function (): void {
    DB::statement('CREATE TEMPORARY TABLE ext_dest (ref VARCHAR(255), tracking VARCHAR(255))');
    DB::table('ext_dest')->insert(['ref' => 'A-1', 'tracking' => null]);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'field_mapping' => [],
        'export' => [
            'enabled' => true,
            'query' => 'UPDATE ext_dest SET tracking = :tracking_number WHERE ref = :source_record_id',
        ],
    ]);

    // Extra keys beyond what the query references must be ignored, not bound.
    $source->exportPackage([
        'tracking_number' => 'TRK1',
        'source_record_id' => 'A-1',
        'carrier' => 'USPS',
    ]);

    expect(DB::table('ext_dest')->where('ref', 'A-1')->value('tracking'))->toBe('TRK1');
});
