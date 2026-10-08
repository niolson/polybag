<?php

namespace App\Services\ShipmentImport;

use App\Enums\Deliverability;
use App\Enums\ImportExistingBehavior;
use App\Enums\ShipmentStatus;
use App\Models\DataSource;
use App\Models\Shipment;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ShipmentBatchWriter
{
    private const PRESERVABLE_FIELDS = [
        'first_name', 'last_name', 'company', 'address1', 'address2', 'city',
        'state_or_province', 'postal_code', 'country', 'phone', 'phone_e164',
        'phone_extension', 'email', 'residential',
        ...Shipment::CUSTOMS_FIELDS,
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
        $readdressed = [];
        $remethodedIds = [];
        $updatedSourceRecordIds = [];
        $skippedSourceRecordIds = [];

        foreach ($preparedRows as $row) {
            $existing = $existingShipments->get($row['source_record_id']);
            $preserveExistingFields = $row['_preserve_existing_fields'] ?? [];
            $customsWarnings = $row['_customs_warnings'] ?? [];
            unset($row['_preserve_existing_fields'], $row['_customs_warnings']);

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

                $changes = Shipment::addressChanges($existing->getAttributes(), $row);

                if ($changes !== null) {
                    $readdressed[$existing->id] = $changes;
                } else {
                    // The validation result stays, so its message must too:
                    // `validation_message` also carries the import's phone and
                    // email warnings, and a re-import would otherwise replace
                    // a validator's reason with them (usually with null).
                    if ($existing->deliverability !== Deliverability::NotChecked) {
                        $row['validation_message'] = self::withWarnings($existing->validation_message, $customsWarnings);
                    }

                    if (array_key_exists('shipping_method_id', $row) && (string) $row['shipping_method_id'] !== (string) $existing->shipping_method_id) {
                        $remethodedIds[] = $existing->id;
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
            'channel_id', 'status', 'pii_purged_at',
            ...Shipment::CUSTOMS_FIELDS,
        ];

        // A written row carries the recipient's data again, so a Shipment an
        // earlier purge cleared needs its retention clock back.
        $rowsToWrite = array_map(fn (array $row): array => [...$row, 'pii_purged_at' => null], $rowsToWrite);

        if ($rowsToWrite !== []) {
            Shipment::upsert($rowsToWrite, ['data_source_id', 'source_record_id'], $updateColumns);
        }

        // `upsert()` skips the Shipment saving hook, so apply what it would:
        // a changed address discards the old validation result (rating and
        // labels prefer the validated address), and a changed method gives
        // the validators it allows a turn on the schedule. The import's own
        // `validation_message` was just written, so it is kept.
        if ($readdressed !== []) {
            Shipment::whereIn('id', array_keys($readdressed))
                ->update(Arr::except(Shipment::UNVALIDATED, 'validation_message'));

            foreach ($readdressed as $shipmentId => $changes) {
                Shipment::stampAddressChanged($shipmentId, $changes);
            }
        }

        if ($remethodedIds !== []) {
            Shipment::whereIn('id', $remethodedIds)->update(['validation_attempted_at' => null]);
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
     * A kept validator message with this import's customs warnings added, so
     * a re-import that drops a malformed recipient tax ID or ITN still says
     * so on the Shipment. A warning already there is not repeated.
     *
     * @param  array<int, string>  $warnings
     */
    private static function withWarnings(?string $message, array $warnings): ?string
    {
        $missing = array_filter($warnings, fn (string $warning): bool => ! str_contains((string) $message, $warning));

        return $missing === [] ? $message : implode('; ', array_filter([$message, ...$missing]));
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
