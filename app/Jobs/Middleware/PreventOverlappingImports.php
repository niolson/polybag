<?php

namespace App\Jobs\Middleware;

use App\Jobs\RunDataSourceImportJob;
use App\Jobs\SyncProductCatalogJob;
use App\Listeners\FailQueuedJobOnFatalError;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One import of a connection at a time (`import-memory/02`).
 *
 * In place of Laravel's `WithoutOverlapping`, which does not expose its lock:
 * this hands the lock it took to {@see FailQueuedJobOnFatalError}, so a fatal
 * error releases it at once instead of leaving it held until it expires. A
 * release only succeeds for the execution that owns the lock, so a job that
 * fails while waiting never unlocks an import that is still running.
 *
 * A scheduled import that finds the lock held is skipped, as the scheduler's
 * own overlap guard did: the next tick runs it. Someone who asked for an
 * import waits for it.
 *
 * A product catalog sync (`product-catalog-sync/02`) takes a lock of its own,
 * so a sync and an order import of the same connection may run together:
 * Products are unique by client and SKU (`product-catalog-sync/01`). A second
 * sync of a connection waits for the first.
 */
class PreventOverlappingImports
{
    public function __construct(
        public string $key,
        public int $releaseAfter,
        public int $expiresAfter,
    ) {}

    public static function forDataSource(int $dataSourceId, int $expiresAfter): self
    {
        return new self("data-source-import:{$dataSourceId}", releaseAfter: 60, expiresAfter: $expiresAfter);
    }

    public static function forProductCatalogSync(int $dataSourceId, int $expiresAfter): self
    {
        return new self("product-catalog-sync:{$dataSourceId}", releaseAfter: 60, expiresAfter: $expiresAfter);
    }

    /**
     * @param  Closure(object): void  $next
     */
    public function handle(RunDataSourceImportJob|SyncProductCatalogJob $job, Closure $next): void
    {
        $lock = Cache::lock($this->key, $this->expiresAfter);

        if (! $lock->get()) {
            if ($job instanceof RunDataSourceImportJob && $job->wasScheduled()) {
                Log::info('Skipped a scheduled import: an import of this connection is still running', [
                    'data_source_id' => $job->dataSourceId,
                ]);

                return;
            }

            $job->release($this->releaseAfter);

            return;
        }

        app(FailQueuedJobOnFatalError::class)->releaseIfTheJobDies($lock);

        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }
}
