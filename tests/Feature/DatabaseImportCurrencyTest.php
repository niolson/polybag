<?php

use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Support\Facades\DB;

/**
 * `order-currency/01`: a Database source can map the currency an order was
 * paid in; unmapped, its values are US dollars.
 */
beforeEach(function (): void {
    $this->dataSource = DataSource::factory()->create();

    DB::statement('CREATE TABLE erp_priced_orders (id TEXT, address1 TEXT, city TEXT, zip TEXT, country TEXT, total TEXT, currency_code TEXT)');
    DB::statement('CREATE TABLE erp_priced_order_lines (order_id TEXT, sku TEXT, qty INTEGER)');
});

function insertPricedErpOrder(string $reference, ?string $currency): void
{
    DB::table('erp_priced_orders')->insert([
        'id' => $reference,
        'address1' => '1 Example Street',
        'city' => 'Example City',
        'zip' => 'K1A 0B1',
        'country' => 'CA',
        'total' => '120.00',
        'currency_code' => $currency,
    ]);
}

function importPricedErpOrders(DataSource $dataSource, bool $mapCurrency): ImportResult
{
    $settings = [
        'shipments_query' => 'SELECT * FROM erp_priced_orders',
        'shipment_items_query' => 'SELECT * FROM erp_priced_order_lines WHERE order_id = :shipment_reference',
    ];
    $config = DataSourceFactory::databaseConfigFor($settings, config('database.default'), $dataSource->id);
    $config['field_mapping']['shipment']['total'] = 'value';

    if ($mapCurrency) {
        $config['field_mapping']['shipment']['currency_code'] = 'currency';
    }

    return ShipmentImportService::forSource(new DatabaseSource($config), $dataSource)->import();
}

it('imports a mapped currency, upper-cased', function (): void {
    insertPricedErpOrder('ERP-CAD', 'cad');

    $result = importPricedErpOrders($this->dataSource, mapCurrency: true);

    $shipment = Shipment::where('shipment_reference', 'ERP-CAD')->sole();

    expect($result->hasErrors())->toBeFalse()
        ->and($shipment->currency)->toBe('CAD')
        ->and((float) $shipment->value)->toBe(120.0);
});

it('imports USD when the currency is not mapped, or mapped and blank', function (bool $mapCurrency, ?string $currency): void {
    insertPricedErpOrder('ERP-USD', $currency);

    expect(importPricedErpOrders($this->dataSource, $mapCurrency)->hasErrors())->toBeFalse()
        ->and(Shipment::where('shipment_reference', 'ERP-USD')->sole()->currency)->toBe('USD');
})->with([
    'not mapped' => [false, 'EUR'],
    'mapped, NULL' => [true, null],
    'mapped, blank' => [true, ' '],
]);

it('rejects a row whose currency is not a three-letter code, and imports the rest', function (string $currency): void {
    insertPricedErpOrder('ERP-BAD', $currency);
    insertPricedErpOrder('ERP-GOOD', 'EUR');

    $result = importPricedErpOrders($this->dataSource, mapCurrency: true);

    expect($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toContain('ERP-BAD')
        ->and($result->errors[0])->toContain('Invalid currency')
        ->and(Shipment::where('shipment_reference', 'ERP-BAD')->exists())->toBeFalse()
        ->and(Shipment::where('shipment_reference', 'ERP-GOOD')->sole()->currency)->toBe('EUR');
})->with([
    'a symbol' => ['$'],
    'a name' => ['Dollars'],
    'two letters' => ['US'],
    'digits' => ['840'],
]);

it('keeps a manager\'s currency when a re-import supplies none', function (bool $mapCurrency, ?string $currency): void {
    $this->dataSource->update(['settings' => ['on_existing' => 'update']]);
    insertPricedErpOrder('ERP-KEEP', $currency);

    importPricedErpOrders($this->dataSource, $mapCurrency);
    Shipment::where('shipment_reference', 'ERP-KEEP')->sole()->update(['currency' => 'CAD']);

    $result = importPricedErpOrders($this->dataSource, $mapCurrency);

    expect($result->shipmentsUpdated)->toBe(1)
        ->and(Shipment::where('shipment_reference', 'ERP-KEEP')->sole()->currency)->toBe('CAD');
})->with([
    'not mapped' => [false, 'EUR'],
    'mapped, NULL' => [true, null],
]);

it('updates a Shipment whose currency alone changed at the source', function (): void {
    $this->dataSource->update(['settings' => ['on_existing' => 'update_if_changed']]);
    insertPricedErpOrder('ERP-MOVE', 'CAD');

    importPricedErpOrders($this->dataSource, mapCurrency: true);
    DB::table('erp_priced_orders')->where('id', 'ERP-MOVE')->update(['currency_code' => 'EUR']);

    $result = importPricedErpOrders($this->dataSource, mapCurrency: true);

    expect($result->shipmentsUpdated)->toBe(1)
        ->and(Shipment::where('shipment_reference', 'ERP-MOVE')->sole()->currency)->toBe('EUR');
});
