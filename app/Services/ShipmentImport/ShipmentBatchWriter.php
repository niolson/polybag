<?php

namespace App\Services\ShipmentImport;

use App\Models\ImportSource;
use App\Models\Shipment;

class ShipmentBatchWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $preparedRows
     */
    public function write(array $preparedRows, ImportSource $importSource): ShipmentBatchWriteResult
    {
        if ($preparedRows === []) {
            return new ShipmentBatchWriteResult(collect(), [], [], 0, 0);
        }

        $now = now();

        foreach ($preparedRows as &$preparedRow) {
            $preparedRow['created_at'] = $now;
            $preparedRow['updated_at'] = $now;
        }
        unset($preparedRow);

        $sourceRecordIds = array_column($preparedRows, 'source_record_id');
        $existingSourceRecordIds = Shipment::where('import_source_id', $importSource->id)
            ->whereIn('source_record_id', $sourceRecordIds)
            ->pluck('source_record_id')
            ->all();

        $updateColumns = [
            'shipment_reference',
            'first_name', 'last_name', 'company',
            'address1', 'address2', 'city', 'state_or_province', 'postal_code', 'country',
            'phone', 'phone_e164', 'phone_extension', 'email', 'value',
            'validation_message', 'shipping_method_reference', 'shipping_method_id',
            'channel_reference', 'deliver_by', 'metadata', 'updated_at',
            'channel_id',
        ];

        Shipment::upsert($preparedRows, ['import_source_id', 'source_record_id'], $updateColumns);

        $shipmentsBySourceRecord = Shipment::where('import_source_id', $importSource->id)
            ->whereIn('source_record_id', $sourceRecordIds)
            ->get()
            ->keyBy('source_record_id');

        $existingCount = count($existingSourceRecordIds);

        return new ShipmentBatchWriteResult(
            shipmentsBySourceRecord: $shipmentsBySourceRecord,
            sourceRecordIds: $sourceRecordIds,
            existingSourceRecordIds: $existingSourceRecordIds,
            shipmentsCreated: count($preparedRows) - $existingCount,
            shipmentsUpdated: $existingCount,
        );
    }
}
