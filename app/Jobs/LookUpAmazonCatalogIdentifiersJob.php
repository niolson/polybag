<?php

namespace App\Jobs;

use App\Enums\AmazonCatalogLookupOutcome;
use App\Jobs\Middleware\PreventOverlappingImports;
use App\Models\DataSource;
use App\Services\ProductCatalog\AmazonCatalogLookups;
use App\Services\ProductCatalog\ProductCatalogSync;
use App\Services\ProductCatalog\ProductCatalogSyncTiming;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\Sources\AmazonSource;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One slice of the catalog look-up that follows an Amazon listing sync
 * (`product-catalog-sync/04`): about a minute of `SearchCatalogItems`
 * requests for the SKUs the sync listed ({@see AmazonCatalogLookups}), after
 * which it queues the next slice at the back of the `imports` queue, as a
 * sync's own slices do, so order imports run in between.
 *
 * The sync's run status covers the listings only. A look-up that fails is
 * logged, as the order import's look-up failures are, and leaves the
 * Products' look-up stamps as they were, so the next sync asks again.
 */
class LookUpAmazonCatalogIdentifiersJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Slices in a row Amazon could not answer before the chain stops, each
     * {@see RETRY_DELAY_SECONDS} after the last: about ten minutes of an
     * outage. The rows stay, so the next sync's look-up carries on with them.
     *
     * Counted per chain, in the job, rather than per row: a failure that
     * outlasts a few retries is Amazon's or the connection's, not one
     * ASIN's, and a count on each row would need a column only to drop rows
     * an outage had not let anyone ask about.
     */
    public const MAX_FAILED_SLICES = 10;

    public const RETRY_DELAY_SECONDS = 60;

    /**
     * One chain per connection. The lock is released as a slice starts, not
     * as it ends, so the slice can queue the next one: a lock held while it
     * runs would drop that continuation (`product-catalog-sync/02`). A second
     * look-up queued while one waits is dropped; one queued while a slice
     * runs takes over, and that slice's continuation is dropped instead.
     */
    public int $uniqueFor = ProductCatalogSyncTiming::STALE_AFTER_SECONDS;

    public int $timeout = ProductCatalogSyncTiming::JOB_TIMEOUT;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    /**
     * @param  int  $failedSlices  slices in a row that Amazon could not answer
     */
    public function __construct(public int $dataSourceId, public int $failedSlices = 0)
    {
        $this->onConnection('imports')->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return (string) $this->dataSourceId;
    }

    /**
     * One slice of a connection's look-up at a time, should two workers take
     * them, so two never ask about the same rows.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [PreventOverlappingImports::forAmazonCatalogLookup($this->dataSourceId, ProductCatalogSyncTiming::LOCK_SECONDS)];
    }

    /** As long as a sync's slice may wait behind busy order imports. */
    public function retryUntil(): ?DateTimeInterface
    {
        return now()->addSeconds(ProductCatalogSyncTiming::STALE_AFTER_SECONDS);
    }

    public function handle(DataSourceFactory $sources, AmazonCatalogLookups $lookups): void
    {
        $dataSource = DataSource::find($this->dataSourceId);

        if ($dataSource === null) {
            return;
        }

        // What it found could not be written; the rows wait for the next sync.
        if (! $dataSource->syncsProductCatalog() || ! ProductCatalogSync::writesProducts()) {
            Log::info('Skipped an Amazon catalog look-up: the connection can no longer sync its catalog', [
                'data_source_id' => $dataSource->id,
            ]);

            return;
        }

        $source = $sources->make($dataSource);

        if (! $source instanceof AmazonSource) {
            return;
        }

        $catalog = $source->catalogIdentifiers(
            'Amazon SP-API catalog lookup failed during a product catalog sync; those Products keep their barcodes, part numbers and look-up dates until they are asked again',
        );

        match ($lookups->runSlice($dataSource, $catalog)) {
            AmazonCatalogLookupOutcome::Finished => null,
            AmazonCatalogLookupOutcome::More => static::dispatch($this->dataSourceId),
            AmazonCatalogLookupOutcome::Interrupted => $this->retryLater(),
        };
    }

    /**
     * Amazon could not answer: try again in a minute, unless it has failed
     * too many slices in a row.
     */
    private function retryLater(): void
    {
        if ($this->failedSlices + 1 >= self::MAX_FAILED_SLICES) {
            Log::channel((string) config('shipment-import.logging.channel', 'shipment-import'))
                ->warning('An Amazon catalog look-up after a product catalog sync stopped: Amazon did not answer; the next sync carries on with what is left', [
                    'data_source_id' => $this->dataSourceId,
                    'failed_slices' => $this->failedSlices + 1,
                ]);

            return;
        }

        static::dispatch($this->dataSourceId, $this->failedSlices + 1)->delay(self::RETRY_DELAY_SECONDS);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel((string) config('shipment-import.logging.channel', 'shipment-import'))
            ->warning('An Amazon catalog look-up after a product catalog sync stopped; the next sync asks again', [
                'data_source_id' => $this->dataSourceId,
                'error' => $exception?->getMessage(),
            ]);
    }
}
