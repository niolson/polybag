<?php

namespace App\Jobs;

use App\Jobs\Middleware\PreventOverlappingImports;
use App\Models\DataSource;
use App\Services\ProductCatalog\AmazonCatalogLookups;
use App\Services\ProductCatalog\ProductCatalogSync;
use App\Services\ProductCatalog\ProductCatalogSyncTiming;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\Sources\AmazonSource;
use DateTimeInterface;
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
class LookUpAmazonCatalogIdentifiersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = ProductCatalogSyncTiming::JOB_TIMEOUT;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $dataSourceId)
    {
        $this->onConnection('imports')->onQueue('imports');
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
            'Amazon SP-API catalog lookup failed during a product catalog sync; those Products keep their barcodes, part numbers and look-up dates, and the next sync asks again',
        );

        if ($lookups->runSlice($dataSource, $catalog)) {
            static::dispatch($this->dataSourceId);
        }
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
