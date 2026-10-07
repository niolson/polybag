<?php

use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\TaxRegistrationRegime;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Support\Facades\DB;

/**
 * `international-customs-terms/03`: the Database driver's default field mapping
 * carries the six customs fields, checked as the Shipment form checks them,
 * against a stand-in customer table on the test connection.
 */
beforeEach(function (): void {
    $this->dataSource = DataSource::factory()->create();

    DB::statement('CREATE TABLE erp_orders (id TEXT, address1 TEXT, city TEXT, zip TEXT, country TEXT, duties_terms TEXT, seller_tax_regime TEXT, seller_tax_number TEXT, recipient_tax_id_type TEXT, recipient_tax_id TEXT, export_itn TEXT)');
    DB::statement('CREATE TABLE erp_order_lines (order_id TEXT, sku TEXT, qty INTEGER)');
});

/**
 * @param  array<string, ?string>  $customs
 */
function insertErpOrder(string $reference, array $customs = []): void
{
    DB::table('erp_orders')->insert([
        'id' => $reference,
        'address1' => '1 Example Street',
        'city' => 'Example City',
        'zip' => '01000-000',
        'country' => 'BR',
        ...array_fill_keys(Shipment::CUSTOMS_FIELDS, null),
        ...$customs,
    ]);
}

function importErpOrders(DataSource $dataSource): ImportResult
{
    // The default field mapping, unaltered: only the queries are set.
    $source = new DatabaseSource(DataSourceFactory::databaseConfigFor([
        'shipments_query' => 'SELECT * FROM erp_orders',
        'shipment_items_query' => 'SELECT * FROM erp_order_lines WHERE order_id = :shipment_reference',
    ], config('database.default'), $dataSource->id));

    return ShipmentImportService::forSource($source, $dataSource)->import();
}

it('maps each customs column through the default field mapping, normalized', function (): void {
    insertErpOrder('ERP-1', [
        'duties_terms' => 'DDP',
        'seller_tax_regime' => 'IOSS',
        'seller_tax_number' => 'im 0000000001',
        'recipient_tax_id_type' => 'CPF',
        'recipient_tax_id' => '123.456.789-09',
        'export_itn' => 'x00000000000001',
    ]);

    $result = importErpOrders($this->dataSource);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', 'ERP-1')->sole();

    expect($shipment->duties_terms)->toBe(DutiesTerms::Ddp)
        ->and($shipment->seller_tax_regime)->toBe(TaxRegistrationRegime::Ioss)
        ->and($shipment->seller_tax_number)->toBe('IM0000000001')
        ->and($shipment->recipient_tax_id_type)->toBe(RecipientTaxIdType::Cpf)
        ->and($shipment->recipient_tax_id)->toBe('12345678909')
        ->and($shipment->export_itn)->toBe('X00000000000001');
});

it('accepts a well-formed value of each format at import', function (array $customs): void {
    insertErpOrder('ERP-OK', $customs);

    expect(importErpOrders($this->dataSource)->hasErrors())->toBeFalse()
        ->and(Shipment::where('shipment_reference', 'ERP-OK')->exists())->toBeTrue();
})->with([
    'IOSS' => [['seller_tax_regime' => 'ioss', 'seller_tax_number' => 'IM0000000001']],
    'UK VAT' => [['seller_tax_regime' => 'uk_vat', 'seller_tax_number' => 'GB000000001']],
    'VOEC' => [['seller_tax_regime' => 'voec', 'seller_tax_number' => '0000001']],
    'ARN' => [['seller_tax_regime' => 'arn', 'seller_tax_number' => '000000000001']],
    'CPF' => [['recipient_tax_id_type' => 'cpf', 'recipient_tax_id' => '12345678909']],
    'CNPJ' => [['recipient_tax_id_type' => 'cnpj', 'recipient_tax_id' => '11222333000181']],
    'PCCC' => [['recipient_tax_id_type' => 'pccc', 'recipient_tax_id' => 'P123456789012']],
    'ITN' => [['export_itn' => 'X00000000000001']],
]);

it('reports a malformed customs value against its row and imports the rest', function (array $customs, string $reason): void {
    insertErpOrder('ERP-BAD', $customs);
    insertErpOrder('ERP-GOOD');

    $result = importErpOrders($this->dataSource);

    expect($result->shipmentsCreated)->toBe(1)
        ->and(Shipment::where('shipment_reference', 'ERP-BAD')->exists())->toBeFalse()
        ->and(Shipment::where('shipment_reference', 'ERP-GOOD')->exists())->toBeTrue()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toContain('ERP-BAD')
        ->and($result->errors[0])->toContain($reason);
})->with([
    'duties terms' => [['duties_terms' => 'DAP'], "Invalid duties terms 'DAP'"],
    'regime' => [['seller_tax_regime' => 'eori', 'seller_tax_number' => 'IM0000000001'], "Invalid seller tax regime 'eori'"],
    'IOSS' => [['seller_tax_regime' => 'ioss', 'seller_tax_number' => 'IM000000001'], 'IM followed by 10 digits'],
    'UK VAT' => [['seller_tax_regime' => 'uk_vat', 'seller_tax_number' => 'GB0000000001'], 'GB followed by 9 or 12 digits'],
    'VOEC' => [['seller_tax_regime' => 'voec', 'seller_tax_number' => '00000001'], 'must be 7 digits'],
    'ARN' => [['seller_tax_regime' => 'arn', 'seller_tax_number' => '00000000001'], 'must be 12 digits'],
    'a number with no regime' => [['seller_tax_number' => 'IM0000000001'], 'Seller tax regime and seller tax number must be given together'],
    'ID type' => [['recipient_tax_id_type' => 'ssn', 'recipient_tax_id' => '12345678909'], "Invalid recipient tax ID type 'ssn'"],
    'CPF' => [['recipient_tax_id_type' => 'cpf', 'recipient_tax_id' => '12345678900'], 'CPF (Brazil, individual) must be'],
    'CNPJ' => [['recipient_tax_id_type' => 'cnpj', 'recipient_tax_id' => '11222333000182'], 'CNPJ (Brazil, company) must be'],
    'PCCC' => [['recipient_tax_id_type' => 'pccc', 'recipient_tax_id' => '123456789012'], 'P followed by 12 digits'],
    'an ID with no type' => [['recipient_tax_id' => '12345678909'], 'Recipient tax ID type and recipient tax ID must be given together'],
    'ITN' => [['export_itn' => 'NO EEI 30.37(a)'], 'X followed by 14 digits'],
]);

it('keeps a manager\'s customs values when a re-import leaves the columns empty', function (): void {
    insertErpOrder('ERP-KEEP');
    importErpOrders($this->dataSource);

    $shipment = Shipment::where('shipment_reference', 'ERP-KEEP')->sole();
    $shipment->update(['export_itn' => 'X00000000000001', 'duties_terms' => DutiesTerms::Ddu]);

    // A changed row, so `update_if_changed` rewrites it.
    DB::table('erp_orders')->where('id', 'ERP-KEEP')->update(['address1' => '2 Example Street']);
    importErpOrders($this->dataSource);

    $shipment->refresh();

    expect($shipment->address1)->toBe('2 Example Street')
        ->and($shipment->export_itn)->toBe('X00000000000001')
        ->and($shipment->duties_terms)->toBe(DutiesTerms::Ddu);
});

it('replaces customs values a re-import supplies', function (): void {
    insertErpOrder('ERP-SWAP', ['duties_terms' => 'ddu']);
    importErpOrders($this->dataSource);

    DB::table('erp_orders')->where('id', 'ERP-SWAP')->update(['duties_terms' => 'ddp']);
    importErpOrders($this->dataSource);

    expect(Shipment::where('shipment_reference', 'ERP-SWAP')->sole()->duties_terms)->toBe(DutiesTerms::Ddp);
});
