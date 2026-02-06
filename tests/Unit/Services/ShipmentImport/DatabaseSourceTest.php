<?php

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
