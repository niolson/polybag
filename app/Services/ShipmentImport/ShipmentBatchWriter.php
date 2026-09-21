<?php

namespace App\Services\ShipmentImport;

use App\Enums\ImportExistingBehavior;
use App\Enums\ShipmentStatus;
use App\Models\DataSource;
use App\Models\Shipment;
use Illuminate\Support\Collection;

class ShipmentBatchWriter
{
    private const PRESERVABLE_FIELDS = [
        'first_name', 'last_name', 'company', 'address1', 'address2', 'city',
        'state_or_province', 'postal_code', 'country', 'phone', 'phone_e164',
        'phone_extension', 'email', 'residential',
    ];

    /**
     * Write prepared shipment rows, honoring the source's behavior for rows
     * that already exist locally:
     *
     *  - Shipped/void shipments are never updated — they are historical records.
     *  - `update` always rewrites open shipments from the source.
     *  - `skip` never touches existing shipments.
     *  - `update_if_changed` rewrites only when the row's source_checksum differs
     *    from the one stored at the previous import.
     *
     * @param  array<int, array<string, mixed>>  $preparedRows
     */
    public function write(array $preparedRows, DataSource $importSource, ?Collection $existingShipments = null): ShipmentBatchWriteResult
    {
        if ($preparedRows === []) {
            return new ShipmentBatchWriteResult(collect(), [], [], [], 0, 0, 0);
        }

        $behavior = ImportExistingBehavior::tryFrom((string) ($importSource->settings['on_existing'] ?? ''))
            ?? ImportExistingBehavior::default();

        $now = now();

        foreach ($preparedRows as &$preparedRow) {
            $preparedRow['client_id'] ??= $importSource->client_id;
            $preparedRow['created_at'] = $now;
            $preparedRow['updated_at'] = $now;
        }
        unset($preparedRow);

        $sourceRecordIds = array_column($preparedRows, 'source_record_id');

        $existingShipments ??= $this->existingFor($importSource, $sourceRecordIds);

        $rowsToWrite = [];
        $updatedSourceRecordIds = [];
        $skippedSourceRecordIds = [];

        foreach ($preparedRows as $row) {
            $existing = $existingShipments->get($row['source_record_id']);
            $preserveExistingFields = $row['_preserve_existing_fields'] ?? [];
            unset($row['_preserve_existing_fields']);

            if (! $existing) {
                $rowsToWrite[] = $row;

                continue;
            }

            if ($this->shouldUpdate($existing, $row, $behavior)) {
                foreach ($preserveExistingFields as $field) {
                    if (is_string($field) && in_array($field, self::PRESERVABLE_FIELDS, true)) {
                        $row[$field] = $existing->getAttribute($field);
                    }
                }

                $rowsToWrite[] = $row;
                $updatedSourceRecordIds[] = $row['source_record_id'];
            } else {
                $skippedSourceRecordIds[] = $row['source_record_id'];
            }
        }

        $updateColumns = [
            'client_id',
            'location_id', 'data_source_location_id',
            'shipment_reference', 'source_checksum',
            'first_name', 'last_name', 'company',
            'address1', 'address2', 'city', 'state_or_province', 'postal_code', 'country',
            'phone', 'phone_e164', 'phone_extension', 'email', 'value',
            'residential',
            'validation_message', 'shipping_method_reference', 'shipping_method_id',
            'channel_reference', 'deliver_by', 'metadata', 'updated_at',
            'channel_id', 'status',
        ];

        if ($rowsToWrite !== []) {
            Shipment::upsert($rowsToWrite, ['data_source_id', 'source_record_id'], $updateColumns);
        }

        $shipmentsBySourceRecord = Shipment::where('data_source_id', $importSource->id)
            ->whereIn('source_record_id', $sourceRecordIds)
            ->get()
            ->keyBy('source_record_id');

        return new ShipmentBatchWriteResult(
            shipmentsBySourceRecord: $shipmentsBySourceRecord,
            sourceRecordIds: $sourceRecordIds,
            existingSourceRecordIds: $updatedSourceRecordIds,
            skippedSourceRecordIds: $skippedSourceRecordIds,
            shipmentsCreated: count($rowsToWrite) - count($updatedSourceRecordIds),
            shipmentsUpdated: count($updatedSourceRecordIds),
            shipmentsSkipped: count($skippedSourceRecordIds),
        );
    }

    /**
     * @param  array<int, string>  $sourceRecordIds
     * @return Collection<string, Shipment>
     */
    public function existingFor(DataSource $importSource, array $sourceRecordIds): Collection
    {
        return Shipment::where('data_source_id', $importSource->id)
            ->whereIn('source_record_id', $sourceRecordIds)
            ->withExists('packages')
            ->get()
            ->keyBy('source_record_id');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function shouldUpdate(Shipment $existing, array $row, ImportExistingBehavior $behavior): bool
    {
        if ($existing->status !== ShipmentStatus::Open) {
            return false;
        }

        $incomingStatus = ShipmentStatus::tryFrom((string) ($row['status'] ?? '')) ?? ShipmentStatus::Open;

        if ($incomingStatus !== ShipmentStatus::Open && (bool) $existing->getAttribute('packages_exists')) {
            return false;
        }

        return match ($behavior) {
            ImportExistingBehavior::Update => true,
            ImportExistingBehavior::Skip => false,
            ImportExistingBehavior::UpdateIfChanged => $existing->source_checksum !== ($row['source_checksum'] ?? null),
        };
    }
}
