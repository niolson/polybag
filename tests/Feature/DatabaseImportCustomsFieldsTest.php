<?php

use App\Contracts\DataSourceInterface;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\TaxRegistrationRegime;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Support\Collection;
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

function importErpOrders(DataSource $dataSource, string $shipmentsQuery = 'SELECT * FROM erp_orders'): ImportResult
{
    // The default field mapping, unaltered: only the queries are set.
    $source = new DatabaseSource(DataSourceFactory::databaseConfigFor([
        'shipments_query' => $shipmentsQuery,
        'shipment_items_query' => 'SELECT * FROM erp_order_lines WHERE order_id = :shipment_reference',
    ], config('database.default'), $dataSource->id));

    return ShipmentImportService::forSource($source, $dataSource)->import();
}

/**
 * A source that, like Shopify and Amazon today, never sets a customs key.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function customsKeylessSource(array $rows): DataSourceInterface
{
    return new class(collect($rows)) implements DataSourceInterface
    {
        public function __construct(private Collection $rows) {}

        public function fetchShipments(): Collection
        {
            return $this->rows;
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return collect();
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $sourceRecordId): bool
        {
            return false;
        }
    };
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

it('rejects a row whose duties terms or seller registration is malformed, and imports the rest', function (array $customs, string $reason): void {
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
    'duties terms' => [['duties_terms' => 'DAT'], "Invalid duties terms 'DAT'"],
    'regime' => [['seller_tax_regime' => 'eori', 'seller_tax_number' => 'IM0000000001'], "Invalid seller tax regime 'eori'"],
    'IOSS' => [['seller_tax_regime' => 'ioss', 'seller_tax_number' => 'IM000000001'], 'IM followed by 10 digits'],
    'UK VAT' => [['seller_tax_regime' => 'uk_vat', 'seller_tax_number' => 'GB0000000001'], 'GB followed by 9 or 12 digits'],
    'VOEC' => [['seller_tax_regime' => 'voec', 'seller_tax_number' => '00000001'], 'must be 7 digits'],
    'ARN' => [['seller_tax_regime' => 'arn', 'seller_tax_number' => '00000000001'], 'must be 12 digits'],
    'a number with no regime' => [['seller_tax_number' => 'IM0000000001'], 'Seller tax regime and seller tax number must be given together'],
]);

it('reads DAP as DDU at import', function (): void {
    insertErpOrder('ERP-DAP', ['duties_terms' => 'DAP']);

    expect(importErpOrders($this->dataSource)->hasErrors())->toBeFalse()
        ->and(Shipment::where('shipment_reference', 'ERP-DAP')->sole()->duties_terms)->toBe(DutiesTerms::Ddu);
});

it('imports an order with a malformed recipient tax ID or ITN without that value, and says so', function (array $customs, array $dropped, string $reason, ?string $secret): void {
    insertErpOrder('ERP-WARN', ['duties_terms' => 'ddp', ...$customs]);

    $result = importErpOrders($this->dataSource);
    $shipment = Shipment::where('shipment_reference', 'ERP-WARN')->sole();

    expect($result->hasErrors())->toBeFalse()
        ->and($shipment->duties_terms)->toBe(DutiesTerms::Ddp)
        ->and($shipment->only($dropped))->toBe(array_fill_keys($dropped, null))
        ->and($shipment->validation_message)->toContain($reason);

    if ($secret !== null) {
        expect($shipment->validation_message)->not->toContain($secret);
    }
})->with([
    'CPF' => [['recipient_tax_id_type' => 'cpf', 'recipient_tax_id' => '12345678900'], ['recipient_tax_id_type', 'recipient_tax_id'], 'Recipient tax ID not imported: A CPF (Brazil, individual) must be', '12345678900'],
    'CNPJ' => [['recipient_tax_id_type' => 'cnpj', 'recipient_tax_id' => '11222333000182'], ['recipient_tax_id_type', 'recipient_tax_id'], 'CNPJ (Brazil, company) must be', '11222333000182'],
    'PCCC' => [['recipient_tax_id_type' => 'pccc', 'recipient_tax_id' => '123456789012'], ['recipient_tax_id_type', 'recipient_tax_id'], 'P followed by 12 digits', '123456789012'],
    'ID type' => [['recipient_tax_id_type' => 'ssn', 'recipient_tax_id' => '12345678909'], ['recipient_tax_id_type', 'recipient_tax_id'], "'ssn' is not a recipient tax ID type", '12345678909'],
    'an ID with no type' => [['recipient_tax_id' => '12345678909'], ['recipient_tax_id_type', 'recipient_tax_id'], 'recipient tax ID type and recipient tax ID must be given together', '12345678909'],
    'ITN' => [['export_itn' => 'NO EEI 30.37(a)'], ['export_itn'], 'Export ITN not imported: An export ITN must be X followed by 14 digits', null],
]);

it('drops a malformed recipient tax ID on re-import and warns, keeping a validator\'s message', function (bool $validated): void {
    insertErpOrder('ERP-RE', ['recipient_tax_id_type' => 'cpf', 'recipient_tax_id' => '12345678909']);
    importErpOrders($this->dataSource);

    $shipment = Shipment::where('shipment_reference', 'ERP-RE')->sole();

    if ($validated) {
        $shipment->forceFill(['checked' => true, 'deliverability' => 'yes', 'validation_message' => 'Address confirmed deliverable'])->save();
    }

    DB::table('erp_orders')->where('id', 'ERP-RE')->update(['recipient_tax_id' => '12345678900']);
    $result = importErpOrders($this->dataSource);
    importErpOrders($this->dataSource);

    $shipment->refresh();

    expect($result->hasErrors())->toBeFalse()
        ->and($result->shipmentsUpdated)->toBe(1)
        ->and($shipment->recipient_tax_id)->toBeNull()
        ->and($shipment->recipient_tax_id_type)->toBeNull()
        ->and($shipment->validation_message)->toContain('Recipient tax ID not imported')
        ->and(substr_count((string) $shipment->validation_message, 'Recipient tax ID not imported'))->toBe(1)
        ->and($shipment->validation_message)->not->toContain('12345678900');

    if ($validated) {
        expect($shipment->validation_message)->toContain('Address confirmed deliverable');
    }
})->with([
    'unvalidated' => false,
    'validated' => true,
]);

it('clears the stored terms, registration and tax ID when a re-import returns them as NULL, but keeps the ITN', function (): void {
    insertErpOrder('ERP-CLEAR', [
        'duties_terms' => 'ddp',
        'seller_tax_regime' => 'ioss',
        'seller_tax_number' => 'IM0000000001',
        'recipient_tax_id_type' => 'cpf',
        'recipient_tax_id' => '12345678909',
        'export_itn' => 'X00000000000001',
    ]);
    importErpOrders($this->dataSource);

    DB::table('erp_orders')->where('id', 'ERP-CLEAR')->update(array_fill_keys(Shipment::CUSTOMS_FIELDS, null));
    importErpOrders($this->dataSource);

    expect(Shipment::where('shipment_reference', 'ERP-CLEAR')->sole()->only(Shipment::CUSTOMS_FIELDS))->toBe([
        'duties_terms' => null,
        'seller_tax_regime' => null,
        'seller_tax_number' => null,
        'recipient_tax_id_type' => null,
        'recipient_tax_id' => null,
        'export_itn' => 'X00000000000001',
    ]);
});

it('keeps a manager\'s ITN when a re-import returns the column as NULL, and clears the duties terms', function (): void {
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
        ->and($shipment->duties_terms)->toBeNull();
});

it('keeps every manager-entered customs value when the query does not select the columns', function (): void {
    insertErpOrder('ERP-ABSENT');
    importErpOrders($this->dataSource);

    $shipment = Shipment::where('shipment_reference', 'ERP-ABSENT')->sole();
    $shipment->update([
        'duties_terms' => DutiesTerms::Ddp,
        'seller_tax_regime' => TaxRegistrationRegime::Ioss,
        'seller_tax_number' => 'IM0000000001',
        'recipient_tax_id_type' => RecipientTaxIdType::Cpf,
        'recipient_tax_id' => '12345678909',
        'export_itn' => 'X00000000000001',
    ]);
    $before = $shipment->refresh()->only(Shipment::CUSTOMS_FIELDS);

    DB::table('erp_orders')->where('id', 'ERP-ABSENT')->update(['address1' => '2 Example Street']);
    importErpOrders($this->dataSource, 'SELECT id, address1, city, zip, country FROM erp_orders');

    $shipment->refresh();

    expect($shipment->address1)->toBe('2 Example Street')
        ->and($shipment->only(Shipment::CUSTOMS_FIELDS))->toBe($before);
});

it('keeps every manager-entered customs value on re-import from a source that never supplies them', function (): void {
    // Shopify and Amazon set none of these keys today.
    $row = ['shipment_reference' => 'SHOP-1', 'address1' => '1 Example Street', 'city' => 'Example City', 'postal_code' => '01000-000', 'country' => 'BR'];
    ShipmentImportService::forSource(customsKeylessSource([$row]), $this->dataSource)->import();

    $shipment = Shipment::where('shipment_reference', 'SHOP-1')->sole();
    $shipment->update([
        'duties_terms' => DutiesTerms::Ddp,
        'seller_tax_regime' => TaxRegistrationRegime::UkVat,
        'seller_tax_number' => 'GB000000001',
        'recipient_tax_id_type' => RecipientTaxIdType::Pccc,
        'recipient_tax_id' => 'P123456789012',
        'export_itn' => 'X00000000000001',
    ]);
    $before = $shipment->refresh()->only(Shipment::CUSTOMS_FIELDS);

    ShipmentImportService::forSource(customsKeylessSource([[...$row, 'address1' => '2 Example Street']]), $this->dataSource)->import();

    $shipment->refresh();

    expect($shipment->address1)->toBe('2 Example Street')
        ->and($shipment->only(Shipment::CUSTOMS_FIELDS))->toBe($before);
});

it('replaces customs values a re-import supplies', function (): void {
    insertErpOrder('ERP-SWAP', ['duties_terms' => 'ddu']);
    importErpOrders($this->dataSource);

    DB::table('erp_orders')->where('id', 'ERP-SWAP')->update(['duties_terms' => 'ddp']);
    importErpOrders($this->dataSource);

    expect(Shipment::where('shipment_reference', 'ERP-SWAP')->sole()->duties_terms)->toBe(DutiesTerms::Ddp);
});
