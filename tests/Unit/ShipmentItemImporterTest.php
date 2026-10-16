<?php

use App\Models\DataSource;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\ShipmentImport\ImportReferenceResolver;
use App\Services\ShipmentImport\ShipmentItemImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an empty authoritative source result does not delete existing shipment items', function (): void {
    [$shipment, $source] = authoritativeShipmentWithItems(3);

    shipmentItemImporter()->import($shipment, collect(), $source);

    expect($shipment->shipmentItems()->count())->toBe(3);
});

test('unresolvable authoritative source rows do not delete existing shipment items', function (): void {
    [$shipment, $source] = authoritativeShipmentWithItems(2);

    shipmentItemImporter()->import($shipment, collect([
        ['name' => 'Missing identity', 'quantity' => 1],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(2);
});

test('a resolved authoritative source snapshot removes stale unpacked items', function (): void {
    [$shipment, $source] = authoritativeShipmentWithItems(2);
    $retained = $shipment->shipmentItems()->with('product')->firstOrFail();

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $retained->product->sku, 'name' => $retained->product->name, 'quantity' => 4],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(1)
        ->and($retained->refresh()->quantity)->toBe(4);
});

test('a partially unresolvable authoritative snapshot preserves potentially unmatched items', function (): void {
    [$shipment, $source] = authoritativeShipmentWithItems(2);
    $retained = $shipment->shipmentItems()->with('product')->firstOrFail();

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $retained->product->sku, 'name' => $retained->product->name, 'quantity' => 4],
        ['name' => 'Missing identity', 'quantity' => 1],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(2)
        ->and($retained->refresh()->quantity)->toBe(4);
});

test('source item identifiers keep two order lines for the same product distinct', function (): void {
    $source = DataSource::factory()->create();
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $product = Product::factory()->create(['sku' => 'SHARED-SKU']);

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $product->sku, 'source_item_id' => 'LINE-1', 'quantity' => 1],
        ['sku' => $product->sku, 'source_item_id' => 'LINE-2', 'quantity' => 2],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(2)
        ->and($shipment->shipmentItems()->orderBy('source_item_id')->pluck('source_item_id')->all())
        ->toBe(['LINE-1', 'LINE-2']);
});

test('an import without a source item identifier preserves an existing identifier', function (): void {
    $source = DataSource::factory()->create();
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $product = Product::factory()->create(['sku' => 'PRESERVE-SKU']);
    $item = ShipmentItem::factory()->create([
        'shipment_id' => $shipment,
        'product_id' => $product,
        'source_item_id' => 'EXTERNAL-LINE-1',
    ]);

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $product->sku, 'quantity' => 3],
    ]), $source);

    expect($item->refresh()->source_item_id)->toBe('EXTERNAL-LINE-1')
        ->and($item->quantity)->toBe(3);
});

test('source-less duplicate product rows retain per-product merge semantics', function (): void {
    $source = DataSource::factory()->create();
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $product = Product::factory()->create(['sku' => 'DUPLICATE-SKU']);

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $product->sku, 'quantity' => 3],
        ['sku' => $product->sku, 'quantity' => 7],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(1)
        ->and($shipment->shipmentItems()->firstOrFail()->quantity)->toBe(7);
});

test('a source item identifier is backfilled onto a matching legacy line', function (): void {
    $source = DataSource::factory()->create();
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $product = Product::factory()->create(['sku' => 'LEGACY-SKU']);
    $item = ShipmentItem::factory()->create([
        'shipment_id' => $shipment,
        'product_id' => $product,
        'source_item_id' => null,
    ]);

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $product->sku, 'source_item_id' => 'BACKFILLED-LINE', 'quantity' => 2],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(1)
        ->and($item->refresh()->source_item_id)->toBe('BACKFILLED-LINE');
});

test('re-importing a source item updates the uniquely identified row', function (): void {
    $source = DataSource::factory()->create();
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);
    $product = Product::factory()->create(['sku' => 'REIMPORT-SKU']);

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $product->sku, 'source_item_id' => 'REIMPORT-LINE', 'quantity' => 1],
    ]), $source);
    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $product->sku, 'source_item_id' => 'REIMPORT-LINE', 'quantity' => 4],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(1)
        ->and($shipment->shipmentItems()->firstOrFail()->quantity)->toBe(4);
});

/** @return array{Shipment, DataSource} */
function authoritativeShipmentWithItems(int $count): array
{
    $source = DataSource::factory()->shopify()->create();
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);

    Product::factory()->count($count)->create()->each(function (Product $product) use ($shipment): void {
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment,
            'product_id' => $product,
        ]);
    });

    return [$shipment, $source];
}

function shipmentItemImporter(): ShipmentItemImporter
{
    return new ShipmentItemImporter(app(ImportReferenceResolver::class));
}
