<?php

use App\Enums\AuditAction;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\ImportResult;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `order-reconciliation/08`: a Database source's status query is asked about
 * every Shipment still open here, after each import, and the Shipments it
 * reports shipped or cancelled are closed.
 */
beforeEach(function (): void {
    $this->dataSource = DataSource::factory()->create();

    // A stand-in ERP: one row per parcel, and a shipments feed already read.
    DB::statement('CREATE TABLE erp_orders (id TEXT, address1 TEXT, city TEXT)');
    DB::statement('CREATE TABLE erp_shipments (order_no TEXT, state TEXT, carrier TEXT, tracking_no TEXT)');
});

const ERP_STATUS_QUERY = <<<'SQL'
    SELECT CASE state
             WHEN 'delivered' THEN 'shipped'
             WHEN 'new' THEN 'open'
             WHEN 'picking' THEN 'open'
             ELSE state
           END AS status,
           carrier AS external_carrier,
           tracking_no AS external_tracking_number
      FROM erp_shipments
     WHERE order_no = :shipment_reference
    SQL;

function openErpShipment(DataSource $dataSource, string $reference, array $attributes = []): Shipment
{
    return Shipment::factory()->create([
        'data_source_id' => $dataSource->id,
        'source_record_id' => $reference,
        'shipment_reference' => $reference,
        'status' => ShipmentStatus::Open,
        'status_origin' => null,
        ...$attributes,
    ]);
}

function erpShipmentRow(string $reference, ?string $state, ?string $carrier = null, ?string $trackingNumber = null): void
{
    DB::table('erp_shipments')->insert([
        'order_no' => $reference,
        'state' => $state,
        'carrier' => $carrier,
        'tracking_no' => $trackingNumber,
    ]);
}

function importWithStatusQuery(DataSource $dataSource, ?string $statusQuery = ERP_STATUS_QUERY): ImportResult
{
    $source = new DatabaseSource(DataSourceFactory::databaseConfigFor([
        'shipments_query' => 'SELECT * FROM erp_orders',
        'status_query' => $statusQuery,
    ], config('database.default'), $dataSource->id));

    return ShipmentImportService::forSource($source, $dataSource)->import();
}

/**
 * @return Collection<int, AuditLog>
 */
function statusLookupAudits(): Collection
{
    return AuditLog::query()
        ->where('action', AuditAction::DataSourceQueryExecuted)
        ->get()
        ->filter(fn (AuditLog $log): bool => ($log->metadata['operation'] ?? null) === 'lookup_status')
        ->values();
}

it('cancels a Shipment the source reports cancelled', function (string $state): void {
    $shipment = openErpShipment($this->dataSource, 'SO-1');
    erpShipmentRow('SO-1', $state);

    $result = importWithStatusQuery($this->dataSource);

    $fresh = $shipment->fresh();

    expect($result->shipmentsClosed)->toBe(1)
        ->and($result->errors)->toBe([])
        ->and($fresh->status)->toBe(ShipmentStatus::Cancelled)
        ->and($fresh->status_origin)->toBe(ShipmentStatusOrigin::Source);
})->with(['cancelled', 'CANCELLED', ' Cancelled ', 'void']);

it('ships a Shipment the source reports shipped, with the first parcel that is not PolyBag\'s', function (): void {
    $shipment = openErpShipment($this->dataSource, 'SO-1');
    $package = Package::factory()->for($shipment)->create();
    PackageLabel::factory()->voided()->for($package)->create(['tracking_number' => 'POLYBAG-VOIDED']);
    erpShipmentRow('SO-1', 'delivered', 'UPS', 'POLYBAG-VOIDED');
    erpShipmentRow('SO-1', 'delivered', 'FedEx', '7946 0000 1111');

    $result = importWithStatusQuery($this->dataSource);

    $fresh = $shipment->fresh();

    expect($result->shipmentsClosed)->toBe(1)
        ->and($fresh->status)->toBe(ShipmentStatus::Shipped)
        ->and($fresh->status_origin)->toBe(ShipmentStatusOrigin::Source)
        ->and($fresh->external_carrier)->toBe('FedEx')
        ->and($fresh->external_tracking_number)->toBe('7946 0000 1111');
});

it('ships a Shipment whose source lists no parcel', function (): void {
    $shipment = openErpShipment($this->dataSource, 'SO-1');
    erpShipmentRow('SO-1', 'shipped');

    importWithStatusQuery($this->dataSource);

    $fresh = $shipment->fresh();

    expect($fresh->status)->toBe(ShipmentStatus::Shipped)
        ->and($fresh->external_carrier)->toBeNull()
        ->and($fresh->external_tracking_number)->toBeNull();
});

it('leaves a Shipment open when the only parcel the source lists is a voided PolyBag Label', function (): void {
    $shipment = openErpShipment($this->dataSource, 'SO-1');
    $package = Package::factory()->for($shipment)->create();
    PackageLabel::factory()->voided()->for($package)->create(['tracking_number' => '9400111899223344556677']);

    // The export query wrote PolyBag's tracking number back to the row.
    erpShipmentRow('SO-1', 'shipped', 'USPS', '9400 1118 9922 3344 5566 77');

    $result = importWithStatusQuery($this->dataSource);

    expect($result->shipmentsClosed)->toBe(0)
        ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Open);
});

it('changes nothing when the source reports the order open or returns no row', function (): void {
    $stillOpen = openErpShipment($this->dataSource, 'SO-1');
    $deleted = openErpShipment($this->dataSource, 'SO-2');
    erpShipmentRow('SO-1', 'picking');

    $result = importWithStatusQuery($this->dataSource);

    expect($result->shipmentsClosed)->toBe(0)
        ->and($result->errors)->toBe([])
        ->and($stillOpen->fresh()->status)->toBe(ShipmentStatus::Open)
        ->and($deleted->fresh()->status)->toBe(ShipmentStatus::Open)
        ->and($deleted->fresh()->status_origin)->toBeNull();
});

it('reports rows that disagree and an unknown status, and changes neither Shipment', function (): void {
    $conflicting = openErpShipment($this->dataSource, 'SO-1');
    $unknown = openErpShipment($this->dataSource, 'SO-2');
    $cancelled = openErpShipment($this->dataSource, 'SO-3');
    erpShipmentRow('SO-1', 'shipped', 'UPS', '1Z999');
    erpShipmentRow('SO-1', 'cancelled');
    erpShipmentRow('SO-2', 'on_hold');
    erpShipmentRow('SO-3', 'cancelled');

    $result = importWithStatusQuery($this->dataSource);

    expect($result->shipmentsClosed)->toBe(1)
        ->and($conflicting->fresh()->status)->toBe(ShipmentStatus::Open)
        ->and($unknown->fresh()->status)->toBe(ShipmentStatus::Open)
        ->and($cancelled->fresh()->status)->toBe(ShipmentStatus::Cancelled)
        ->and($result->errors)->toBe([
            'Status query returned conflicting statuses for shipment SO-1: shipped, cancelled',
            "Status query returned an unknown status for shipment SO-2: 'on_hold'",
        ]);
});

it('closes a Shipment with a Package Draft and keeps the draft', function (): void {
    $shipment = openErpShipment($this->dataSource, 'SO-1');
    $draft = Package::factory()->for($shipment)->create();
    erpShipmentRow('SO-1', 'cancelled');

    importWithStatusQuery($this->dataSource);

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled)
        ->and(Package::find($draft->id))->not->toBeNull();
});

it('does not look up a Shipment a manager reopened', function (): void {
    $reopened = openErpShipment($this->dataSource, 'SO-1', ['status_origin' => ShipmentStatusOrigin::Manager]);
    $other = openErpShipment($this->dataSource, 'SO-2');
    erpShipmentRow('SO-1', 'cancelled');
    erpShipmentRow('SO-2', 'new');

    importWithStatusQuery($this->dataSource);

    expect($reopened->fresh()->status)->toBe(ShipmentStatus::Open)
        ->and($reopened->fresh()->status_origin)->toBe(ShipmentStatusOrigin::Manager)
        ->and(statusLookupAudits()->sole()->metadata['shipments_checked'])->toBe(1)
        ->and($other->fresh()->status)->toBe(ShipmentStatus::Open);
});

it('looks up only this source\'s open Shipments', function (): void {
    openErpShipment($this->dataSource, 'SO-1');
    openErpShipment($this->dataSource, 'SO-2', ['status' => ShipmentStatus::Shipped]);
    $elsewhere = openErpShipment(DataSource::factory()->create(), 'SO-3');
    erpShipmentRow('SO-3', 'cancelled');

    importWithStatusQuery($this->dataSource);

    expect(statusLookupAudits()->sole()->metadata['shipments_checked'])->toBe(1)
        ->and($elsewhere->fresh()->status)->toBe(ShipmentStatus::Open);
});

it('writes one audit entry for the run\'s lookups', function (): void {
    foreach (['SO-1', 'SO-2', 'SO-3'] as $reference) {
        openErpShipment($this->dataSource, $reference);
    }

    importWithStatusQuery($this->dataSource);

    $audit = statusLookupAudits()->sole();

    expect($audit->metadata['status'])->toBe('success')
        ->and($audit->metadata['shipments_checked'])->toBe(3)
        ->and($audit->metadata['failed_lookups'])->toBe(0)
        ->and($audit->metadata['parameters'])->toBe(['shipment_reference']);
});

it('stops the lookup at the first failed query and records one error', function (): void {
    openErpShipment($this->dataSource, 'SO-1');
    openErpShipment($this->dataSource, 'SO-2');

    $result = importWithStatusQuery($this->dataSource, 'SELECT status FROM missing_table WHERE id = :shipment_reference');

    $audit = statusLookupAudits()->sole();

    expect($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toStartWith('Status query failed for shipment SO-1:')
        ->and($audit->metadata['status'])->toBe('failed')
        ->and($audit->metadata['shipments_checked'])->toBe(1)
        ->and($audit->metadata['failed_lookups'])->toBe(1);
});

it('asks nothing without a status query', function (): void {
    $shipment = openErpShipment($this->dataSource, 'SO-1');
    erpShipmentRow('SO-1', 'cancelled');

    $result = importWithStatusQuery($this->dataSource, null);

    expect($result->shipmentsClosed)->toBe(0)
        ->and($shipment->fresh()->status)->toBe(ShipmentStatus::Open)
        ->and(statusLookupAudits())->toBeEmpty();
});

it('closes a Shipment imported in the same run', function (): void {
    DB::table('erp_orders')->insert(['id' => 'SO-1', 'address1' => '1 Example Street', 'city' => 'Example City']);
    erpShipmentRow('SO-1', 'cancelled');

    $result = importWithStatusQuery($this->dataSource);

    expect($result->shipmentsCreated)->toBe(1)
        ->and($result->shipmentsClosed)->toBe(1)
        ->and(Shipment::where('source_record_id', 'SO-1')->sole()->status)->toBe(ShipmentStatus::Cancelled);
});
