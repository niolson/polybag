<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ClosesRecordsClosedAtSource;
use App\Contracts\DataSourceInterface;
use App\Contracts\ReadsInPages;
use App\Contracts\ReconcilesSupersededRecords;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\AddressReferenceService;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShipmentImportService
{
    public function __construct(
        private readonly DataSourceInterface $source,
        private readonly ImportReferenceResolver $references,
        private readonly ShipmentRowPreparer $rowPreparer,
        private readonly ShipmentBatchWriter $batchWriter,
        private readonly ShipmentItemImporter $itemImporter,
        private readonly ImportRunRecorder $runRecorder,
        private readonly DataSource $importSource,
        private readonly DataSourceLocationResolver $locationResolver,
    ) {}

    /**
     * Build a service with an explicit driver instance and record.
     * Callers must supply a real DataSource record — no auto-creation.
     */
    public static function forSource(DataSourceInterface $source, DataSource $record): self
    {
        $references = app(ImportReferenceResolver::class);

        return new self(
            source: $source,
            references: $references,
            rowPreparer: new ShipmentRowPreparer(app(AddressReferenceService::class), $references),
            batchWriter: app(ShipmentBatchWriter::class),
            itemImporter: new ShipmentItemImporter($references),
            runRecorder: ImportRunRecorder::forRecord($record),
            importSource: $record,
            locationResolver: app(DataSourceLocationResolver::class),
        );
    }

    /**
     * Build a service directly from an DataSource DB record.
     */
    /**
     * @param  array<string, mixed>  $sourceOverrides
     */
    public static function forRecord(DataSource $record, array $sourceOverrides = []): self
    {
        $source = app(DataSourceFactory::class)->make($record, $sourceOverrides);
        $references = app(ImportReferenceResolver::class);

        return new self(
            source: $source,
            references: $references,
            rowPreparer: new ShipmentRowPreparer(app(AddressReferenceService::class), $references),
            batchWriter: app(ShipmentBatchWriter::class),
            itemImporter: new ShipmentItemImporter($references),
            runRecorder: ImportRunRecorder::forRecord($record),
            importSource: $record,
            locationResolver: app(DataSourceLocationResolver::class),
        );
    }

    /**
     * Run the import process
     */
    public function import(): ImportResult
    {
        $this->applyMemoryLimit();
        memory_reset_peak_usage();

        $startTime = microtime(true);

        try {
            $this->source->validateConfiguration();
        } catch (\Exception $e) {
            return $this->runRecorder->configurationFailed($e->getMessage(), microtime(true) - $startTime);
        }

        $this->references->warm($this->importSource->client);

        try {
            if ($this->source instanceof ReadsInPages) {
                $fetched = $this->source->fetchIdentities();
                $pages = $this->source->fetchShipmentPages();
            } else {
                $fetched = $this->source->fetchShipments();
                $pages = [$fetched];
            }
        } catch (\Exception $e) {
            return $this->runRecorder->configurationFailed($e->getMessage(), microtime(true) - $startTime);
        }

        $this->runRecorder->started($fetched->count());

        if ($this->source instanceof ReconcilesSupersededRecords) {
            $this->reconcileSupersededRecords($this->source, $fetched);
        }

        $readCompleted = Shipment::withoutSyncingToSearch(fn (): bool => $this->writePages($pages));

        if ($readCompleted && $this->source instanceof ClosesRecordsClosedAtSource) {
            $this->closeRecordsClosedAtSource($this->source, $fetched);
        }

        return $this->runRecorder->completed(microtime(true) - $startTime);
    }

    /**
     * Write each page in batches, reading the next only once the last is
     * written, so the run holds one page of rows at a time.
     *
     * A page that cannot be read stops the run's reading. What was written
     * stays, the error is recorded, and the rest waits for the next run.
     *
     * @param  iterable<int, Collection<int, array<string, mixed>>>  $pages
     * @return bool whether every page was read
     */
    private function writePages(iterable $pages): bool
    {
        $batchSize = config('shipment-import.behavior.batch_size', 100);
        $pages = (fn (): Generator => yield from $pages)();

        $advance = false;

        while (true) {
            // Only the read is caught here: a failed write is not a failed read.
            try {
                if ($advance) {
                    $pages->next();
                }

                if (! $pages->valid()) {
                    return true;
                }

                $page = $pages->current();
            } catch (\Exception $e) {
                $this->runRecorder->addError(
                    'The source stopped answering partway through the read; what was read is imported and the rest waits for the next run: '.ImportRunRecorder::reasonFor($e),
                    ['error' => $e->getMessage()],
                    'error',
                );

                return false;
            }

            $page->chunk($batchSize)->each(function (Collection $batch): void {
                DB::transaction(function () use ($batch): void {
                    $this->importBatch($batch);
                });
            });

            // Let go before the next is read, so the source's next yield frees it.
            unset($page);
            $advance = true;
        }
    }

    /**
     * Set the run's memory limit, whatever the worker was started with, so an
     * import is held to the same ceiling under `queue:listen` in development
     * (whose child workers get no `-d` flags) as under the Docker worker.
     *
     * Not restored afterwards: lowering the limit below what the process still
     * holds throws, and the source's caches outlive this method. A worker that
     * runs imports keeps the import limit.
     */
    private function applyMemoryLimit(): void
    {
        $limit = config('shipment-import.behavior.memory_limit');

        if (filled($limit)) {
            ini_set('memory_limit', (string) $limit);
        }
    }

    /**
     * Close the Shipments whose orders the source closed after they left the
     * feed (`order-reconciliation` decision 2). After the writes, so a row
     * this run fetched is never mistaken for one that left, and outside
     * `withoutSyncingToSearch()`, so a source's tracking number is searchable.
     *
     * Like {@see reconcileSupersededRecords()}, a failure is recorded and the
     * run completes: the Shipments wait for the next run.
     *
     * @param  Collection<int, mixed>  $shipments  every row fetched, or a paged source's identities
     */
    private function closeRecordsClosedAtSource(ClosesRecordsClosedAtSource $source, Collection $shipments): void
    {
        try {
            $result = $source->closeRecordsClosedAtSource($this->importSource, $shipments);
        } catch (\Exception $e) {
            $this->runRecorder->addError('Could not check whether the source closed any open shipment: '.ImportRunRecorder::reasonFor($e), ['error' => $e->getMessage()]);

            return;
        }

        $this->runRecorder->addStats(['shipments_closed' => $result->closed]);

        foreach ($result->errors as $error) {
            $this->runRecorder->addError($error);
        }
    }

    /**
     * Give a source that re-keys its records the chance to re-point the
     * shipments it already owns, before the batch write keys off the new
     * identifier and imports them a second time.
     *
     * Run against the whole fetched set, never a chunk or a page: whether the
     * record a shipment names is still on offer cannot be answered from part
     * of it. A source that reads in pages is given its identities.
     *
     * A failure here is recorded and the import carries on. The cost of not
     * reconciling is a duplicate shipment, which a packer can see and a person
     * can void; the cost of abandoning the run is every other shipment in it.
     *
     * @param  Collection<int, mixed>  $shipments  every row fetched, or a paged source's identities
     */
    private function reconcileSupersededRecords(ReconcilesSupersededRecords $source, Collection $shipments): void
    {
        try {
            // Deliberately not counted here. A re-pointed shipment is still in
            // the fetched set, so the batch write below reports it as the
            // update or skip it now is; counting it twice would overstate the run.
            $source->reconcileSupersededRecords($this->importSource, $shipments);
        } catch (\Exception $e) {
            $this->runRecorder->addError(
                'Could not check whether any imported record had been replaced upstream: '.ImportRunRecorder::reasonFor($e),
                ['error' => $e->getMessage()],
            );
        }
    }

    private function importBatch(Collection $batch): void
    {
        $preparedRows = [];
        $validDataBySourceRecord = [];
        $itemsBySourceRecord = [];
        $clientColumn = $this->importSource->settings['client_column'] ?? null;
        $itemsEnabled = $this->itemImporter->isEnabledFor($this->importSource);
        $locationResolutions = $this->locationResolver->resolveBatch(
            $this->importSource,
            $batch->pluck('source_location')->filter(fn (mixed $reference): bool => is_array($reference))->values(),
        );
        $existingShipments = $this->batchWriter->existingFor(
            $this->importSource,
            $batch->map(fn (array $data): mixed => $data['source_record_id'] ?? $data['shipment_reference'] ?? null)
                ->filter()
                ->map(fn (mixed $id): string => (string) $id)
                ->values()
                ->all(),
        );

        foreach ($batch as $data) {
            try {
                if (isset($data['source_location'])) {
                    $externalLocationId = $data['source_location']['external_id'] ?? null;
                    if (blank($externalLocationId)) {
                        throw new \InvalidArgumentException('Source location is missing its external identifier.');
                    }

                    $resolution = $locationResolutions->get((string) $externalLocationId)
                        ?? throw new \InvalidArgumentException("Source location {$externalLocationId} could not be resolved.");

                    if ($resolution->ignored) {
                        $this->runRecorder->increment('shipments_skipped');

                        continue;
                    }

                    if ($resolution->error !== null) {
                        $this->runRecorder->increment('shipments_skipped');
                        $this->runRecorder->addError($resolution->error, [
                            'shipment_reference' => $data['shipment_reference'] ?? 'unknown',
                            'source_location_id' => $resolution->sourceLocation->external_id,
                        ]);

                        continue;
                    }

                    $data['location_id'] = $resolution->location?->id;
                    $data['data_source_location_id'] = $resolution->sourceLocation->id;

                    $existing = $existingShipments->get((string) ($data['source_record_id'] ?? ''));

                    if ($existing
                        && $existing->location_id !== $data['location_id']
                        && (bool) $existing->getAttribute('packages_exists')) {
                        $this->runRecorder->increment('shipments_skipped');
                        $this->runRecorder->addError(
                            "Shipment {$existing->shipment_reference} was reassigned after packing began; its existing Package location was preserved.",
                            ['shipment_id' => $existing->id],
                        );

                        continue;
                    }
                }

                $clientOverride = null;
                if ($clientColumn && isset($data['_client_column_value'])) {
                    $clientOverride = Client::findByImportName((string) $data['_client_column_value']);
                }

                $prepared = $this->rowPreparer->prepare($data, $this->importSource, $clientOverride);

                if (! $prepared->isValid()) {
                    $this->runRecorder->addError(
                        "Validation errors for shipment {$data['shipment_reference']}: ".implode(', ', $prepared->errors),
                        [
                            'shipment_reference' => $data['shipment_reference'] ?? 'unknown',
                            'errors' => $prepared->errors,
                        ],
                    );

                    continue;
                }

                $attributes = $prepared->attributes;
                $sourceRecordId = (string) $attributes['source_record_id'];

                // Items are fetched up front so the checksum covers both the
                // shipment row and its items.
                $items = $itemsEnabled
                    ? $this->source->fetchShipmentItems($sourceRecordId)
                    : collect();

                $attributes['source_checksum'] = $this->sourceChecksum($attributes, $items);

                $preparedRows[] = $attributes;
                $validDataBySourceRecord[$sourceRecordId] = $data;
                $itemsBySourceRecord[$sourceRecordId] = $items;
            } catch (\Exception $e) {
                $this->runRecorder->recordImportError($data, $e);
            }
        }

        if ($preparedRows === []) {
            return;
        }

        $writeResult = $this->batchWriter->write($preparedRows, $this->importSource, $existingShipments);

        $this->runRecorder->addStats([
            'shipments_created' => $writeResult->shipmentsCreated,
            'shipments_updated' => $writeResult->shipmentsUpdated,
            'shipments_skipped' => $writeResult->shipmentsSkipped,
        ]);

        foreach ($validDataBySourceRecord as $sourceRecordId => $data) {
            $shipment = $writeResult->shipmentsBySourceRecord[$sourceRecordId] ?? null;

            if (! $shipment) {
                continue;
            }

            // Skipped shipments keep their local state untouched, but the
            // source record is still marked exported so it stops re-fetching.
            if ($writeResult->wasSkipped($sourceRecordId)) {
                $this->markSourceRecordExported($sourceRecordId, $data);

                continue;
            }

            $this->runRecorder->addStats($this->itemImporter->import($shipment, $itemsBySourceRecord[$sourceRecordId] ?? collect(), $this->importSource));
            $this->runRecorder->recordShipmentEvent($shipment, $writeResult->wasExisting($sourceRecordId));

            $this->markSourceRecordExported($sourceRecordId, $data);
        }
    }

    /**
     * Stable fingerprint of a shipment's source data (row + items), used to
     * detect whether the remote record changed since the last import.
     *
     * @param  array<string, mixed>  $attributes
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function sourceChecksum(array $attributes, Collection $items): string
    {
        unset($attributes['source_checksum'], $attributes['_preserve_existing_fields'], $attributes['_customs_warnings']);

        return hash('sha256', json_encode([$attributes, $items->values()->all()]));
    }

    /**
     * Look up a Client by name for multi-client database imports.
     * Returns null (no client scoping) when the name doesn't match any record.
     */
    private function markSourceRecordExported(string $sourceRecordId, array $data): void
    {
        try {
            if ($this->source->markExported($sourceRecordId)) {
                $this->runRecorder->increment('shipments_exported');
            }
        } catch (\Exception $e) {
            $this->runRecorder->recordSourceExportFailure($sourceRecordId, $data, $e);
        }
    }
}
