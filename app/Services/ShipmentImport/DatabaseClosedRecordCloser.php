<?php

namespace App\Services\ShipmentImport;

use App\DataTransferObjects\ShipmentImport\SourceClosureResult;
use App\DataTransferObjects\ShipmentImport\SourceOrderReport;
use App\DataTransferObjects\ShipmentImport\SourceParcel;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Models\DataSource;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Closes the Shipments a Database source closed (`order-reconciliation/08`).
 *
 * The shipments query usually reads each row once, so a later cancellation
 * never comes back through it. Instead the source's status query is asked
 * about every Shipment still open here, and {@see SourceClosureApplier}
 * closes those it reports shipped or cancelled.
 *
 * A row the query no longer finds changes nothing: a deleted row as a
 * cancellation waits for a tenant that needs it (`08`, Deferred).
 */
class DatabaseClosedRecordCloser
{
    /** The columns the status query returns, read by name ignoring case. */
    public const COLUMNS = ['status', 'external_carrier', 'external_tracking_number'];

    /** Status values the query may return, ignoring case. `void` is the old name for cancelled. */
    private const STATUSES = [
        'open' => ShipmentStatus::Open,
        'shipped' => ShipmentStatus::Shipped,
        'cancelled' => ShipmentStatus::Cancelled,
        'void' => ShipmentStatus::Cancelled,
    ];

    public function __construct(
        private readonly SourceClosureApplier $applier,
    ) {}

    /**
     * A failed lookup stops the run, since a broken query fails the same way
     * for every Shipment; the rest wait for the next run.
     *
     * @param  callable(string): list<array<string, mixed>>  $lookup  the status query's rows for a source record ID
     */
    public function close(DataSource $dataSource, callable $lookup): SourceClosureResult
    {
        $closed = 0;
        $errors = [];

        $shipments = Shipment::query()
            ->where('data_source_id', $dataSource->id)
            ->where('status', ShipmentStatus::Open)
            ->whereNotNull('source_record_id')
            // A Shipment a manager reopened stays open (decision 7).
            ->where(fn (Builder $query) => $query
                ->whereNull('status_origin')
                ->orWhere('status_origin', '!=', ShipmentStatusOrigin::Manager))
            ->lazyById();

        foreach ($shipments as $shipment) {
            try {
                $rows = $lookup($shipment->source_record_id);
            } catch (\Throwable $e) {
                $errors[] = "Status query failed for shipment {$shipment->shipment_reference}: {$e->getMessage()}";

                break;
            }

            $report = $this->report($rows, $shipment, $errors);

            if ($report !== null && $this->applier->apply($shipment, $report)) {
                $closed++;
            }
        }

        return new SourceClosureResult($closed, $errors);
    }

    /**
     * What the rows report, read by the outcomes table in `08`. Rows that
     * disagree, or a status that is not one of {@see STATUSES}, report
     * nothing and add an error.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $errors
     */
    private function report(array $rows, Shipment $shipment, array &$errors): ?SourceOrderReport
    {
        if ($rows === []) {
            return null;
        }

        // Drivers differ in the case of returned column names.
        $rows = array_map(fn (array $row): array => array_change_key_case($row, CASE_LOWER), $rows);

        $values = array_values(array_unique(array_map(
            fn (array $row): string => strtolower(trim((string) ($row['status'] ?? ''))),
            $rows,
        )));

        if (count($values) > 1) {
            $errors[] = "Status query returned conflicting statuses for shipment {$shipment->shipment_reference}: ".implode(', ', $values);

            return null;
        }

        $status = self::STATUSES[$values[0]] ?? null;

        if ($status === null) {
            $errors[] = "Status query returned an unknown status for shipment {$shipment->shipment_reference}: '{$values[0]}'";

            return null;
        }

        return match ($status) {
            ShipmentStatus::Cancelled => SourceOrderReport::cancelled('The source database reported the order cancelled'),
            ShipmentStatus::Shipped => SourceOrderReport::shipped('The source database reported the order shipped', $this->parcels($rows)),
            ShipmentStatus::Open => null,
        };
    }

    /**
     * A parcel for each row naming a carrier or tracking number. A source
     * with one row per parcel lists them all.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<SourceParcel>
     */
    private function parcels(array $rows): array
    {
        $parcels = [];

        foreach ($rows as $row) {
            $carrier = self::filled($row['external_carrier'] ?? null);
            $trackingNumber = self::filled($row['external_tracking_number'] ?? null);

            if ($carrier !== null || $trackingNumber !== null) {
                $parcels[] = new SourceParcel($carrier, $trackingNumber);
            }
        }

        return $parcels;
    }

    private static function filled(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
