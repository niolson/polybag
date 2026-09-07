<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A DatabaseSource bound to the app's own test connection, standing in for a
 * customer database so the preview can be exercised without a live external one.
 */
function previewSource(array $config): DatabaseSource
{
    return new DatabaseSource(array_merge([
        'connection' => config('database.default'),
        'data_source_id' => 11,
        'field_mapping' => [
            'shipment' => [
                'order_no' => 'shipment_reference',
                'ship_to' => 'first_name',
            ],
            'shipment_item' => [
                'item_sku' => 'sku',
                'qty' => 'quantity',
            ],
        ],
    ], $config));
}

function createSourceOrders(): void
{
    DB::statement('CREATE TABLE source_orders (order_no TEXT, ship_to TEXT, status TEXT)');
    DB::table('source_orders')->insert([
        ['order_no' => 'A-1', 'ship_to' => 'Ada', 'status' => 'new'],
        ['order_no' => 'A-2', 'ship_to' => 'Grace', 'status' => 'new'],
    ]);
}

it('returns sample rows both raw and mapped for a SELECT shipments query', function (): void {
    createSourceOrders();

    $preview = previewSource([
        'shipments_query' => 'SELECT * FROM source_orders',
    ])->preview();

    expect($preview->errors)->toBe([])
        ->and($preview->shipments)->toHaveCount(2);

    // The raw row keeps the source's own column names…
    expect($preview->shipments[0]['raw'])->toBe([
        'order_no' => 'A-1',
        'ship_to' => 'Ada',
        'status' => 'new',
    ]);

    // …and the mapped row shows what the field mapping resolves them to.
    expect($preview->shipments[0]['mapped'])->toBe([
        'shipment_reference' => 'A-1',
        'first_name' => 'Ada',
    ]);
});

it('previews the items query bound to the first shipment reference', function (): void {
    createSourceOrders();
    DB::statement('CREATE TABLE source_items (order_no TEXT, item_sku TEXT, qty INTEGER)');
    DB::table('source_items')->insert([
        ['order_no' => 'A-1', 'item_sku' => 'SKU-1', 'qty' => 2],
        ['order_no' => 'A-2', 'item_sku' => 'SKU-9', 'qty' => 1],
    ]);

    $preview = previewSource([
        'shipments_query' => 'SELECT * FROM source_orders',
        'shipment_items_query' => 'SELECT * FROM source_items WHERE order_no = :shipment_reference',
    ])->preview();

    expect($preview->itemsReference)->toBe('A-1')
        ->and($preview->items)->toHaveCount(1)
        ->and($preview->items[0]['raw']['item_sku'])->toBe('SKU-1')
        ->and($preview->items[0]['mapped'])->toBe(['sku' => 'SKU-1', 'quantity' => 2]);
});

it('reports a failing items query without losing the shipment rows', function (): void {
    createSourceOrders();

    $preview = previewSource([
        'shipments_query' => 'SELECT * FROM source_orders',
        'shipment_items_query' => 'SELECT * FROM no_such_table WHERE id = :shipment_reference',
    ])->preview();

    expect($preview->shipments)->toHaveCount(2)
        ->and($preview->items)->toBe([])
        ->and($preview->errors)->toHaveKey('Items query');
});

it('refuses a non-SELECT shipments query without executing it', function (): void {
    createSourceOrders();

    $preview = previewSource([
        'shipments_query' => 'DELETE FROM source_orders',
    ])->preview();

    expect($preview->errors)->toHaveKey('Shipments query')
        ->and($preview->shipments)->toBe([]);

    // The DELETE never ran, and nothing was audited as executed.
    expect(DB::table('source_orders')->count())->toBe(2)
        ->and(AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->exists())->toBeFalse();
});

it('never executes the mark-exported or export queries', function (): void {
    createSourceOrders();

    $preview = previewSource([
        'shipments_query' => 'SELECT * FROM source_orders',
        'mark_exported' => [
            'enabled' => true,
            'query' => "UPDATE source_orders SET status = 'exported' WHERE order_no = :shipment_reference",
        ],
        'export' => [
            'enabled' => true,
            'query' => "UPDATE source_orders SET status = 'tracked' WHERE order_no = :shipment_reference",
        ],
    ])->preview();

    expect($preview->shipments)->toHaveCount(2);
    expect(DB::table('source_orders')->where('status', 'new')->count())->toBe(2);

    $operations = AuditLog::where('action', AuditAction::DataSourceQueryExecuted)
        ->get()
        ->pluck('metadata.operation')
        ->all();

    expect($operations)->toBe(['preview_shipments']);
});

it('stops at the preview row limit without rewriting the operator SQL', function (): void {
    DB::statement('CREATE TABLE source_orders (order_no TEXT, ship_to TEXT, status TEXT)');
    foreach (array_chunk(range(1, 1000), 100) as $chunk) {
        DB::table('source_orders')->insert(array_map(
            fn (int $i): array => ['order_no' => "A-{$i}", 'ship_to' => 'Ada', 'status' => 'new'],
            $chunk,
        ));
    }

    $query = 'SELECT * FROM source_orders';
    $preview = previewSource(['shipments_query' => $query])->preview(5);

    expect($preview->shipments)->toHaveCount(5);

    // The query is executed verbatim — LIMIT/TOP/FETCH FIRST differ per dialect,
    // so the bound is applied by stopping consumption, not by editing the SQL.
    $log = AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->firstOrFail();
    expect($log->metadata['query_preview'])->toBe($query)
        ->and($log->metadata['query_hash'])->toBe(hash('sha256', $query));
});

it('audits the preview without recording any row data', function (): void {
    createSourceOrders();
    DB::statement('CREATE TABLE source_items (order_no TEXT, item_sku TEXT, qty INTEGER)');
    DB::table('source_items')->insert(['order_no' => 'A-1', 'item_sku' => 'SKU-1', 'qty' => 2]);

    previewSource([
        'shipments_query' => 'SELECT * FROM source_orders',
        'shipment_items_query' => 'SELECT * FROM source_items WHERE order_no = :shipment_reference',
    ])->preview();

    $logs = AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->get();

    expect($logs->pluck('metadata.operation')->all())
        ->toBe(['preview_shipments', 'preview_shipment_items']);

    $first = $logs->first();
    expect($first->metadata['status'])->toBe('success')
        ->and($first->metadata['data_source_id'])->toBe(11)
        ->and($first->metadata['preview_rows'])->toBe(2);

    // Row contents — names, references, SKUs — stay in the modal.
    $encoded = $logs->map(fn (AuditLog $log): string => json_encode($log->metadata))->join(' ');
    foreach (['Ada', 'Grace', 'A-1', 'SKU-1'] as $rowValue) {
        expect($encoded)->not->toContain($rowValue);
    }
});

it('previews the table-based query when no custom SQL is configured', function (): void {
    createSourceOrders();

    $preview = previewSource([
        'shipments_table' => 'source_orders',
        'filters' => ['status' => 'new'],
    ])->preview(1);

    expect($preview->errors)->toBe([])
        ->and($preview->shipments)->toHaveCount(1)
        ->and($preview->shipments[0]['mapped']['shipment_reference'])->toBe('A-1');
});
