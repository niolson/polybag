<?php

use App\Models\DataSource;
use App\Models\Product;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Support\Facades\DB;

/**
 * The default Database mapping reads an item's `hs_code`, `country_of_origin` and
 * `mpn` columns into the Product's customs fields.
 */
it('maps hs_code, country_of_origin and mpn onto the product by default', function (): void {
    $dataSource = DataSource::factory()->create();

    DB::statement('CREATE TABLE erp_customs_orders (id TEXT, address1 TEXT, city TEXT, zip TEXT, country TEXT)');
    DB::statement('CREATE TABLE erp_customs_order_lines (order_id TEXT, sku TEXT, quantity INTEGER, hs_code TEXT, country_of_origin TEXT, mpn TEXT)');

    DB::table('erp_customs_orders')->insert([
        'id' => 'ERP-CUSTOMS',
        'address1' => 'PSC 1234 Box 5678',
        'city' => 'APO',
        'zip' => '09001',
        'country' => 'US',
    ]);
    DB::table('erp_customs_order_lines')->insert([
        'order_id' => 'ERP-CUSTOMS',
        'sku' => 'HM-TOWEL',
        'quantity' => 2,
        'hs_code' => '630260',
        'country_of_origin' => 'PT',
        'mpn' => 'WT-4410-SG',
    ]);

    $config = DataSourceFactory::databaseConfigFor([
        'shipments_query' => 'SELECT * FROM erp_customs_orders',
        'shipment_items_query' => 'SELECT * FROM erp_customs_order_lines WHERE order_id = :shipment_reference',
    ], config('database.default'), $dataSource->id);

    $result = ShipmentImportService::forSource(new DatabaseSource($config), $dataSource)->import();

    $product = Product::where('sku', 'HM-TOWEL')->sole();

    expect($result->hasErrors())->toBeFalse()
        ->and($product->hs_tariff_number)->toBe('630260')
        ->and($product->country_of_origin)->toBe('PT')
        ->and($product->manufacturer_part_number)->toBe('WT-4410-SG');
});
