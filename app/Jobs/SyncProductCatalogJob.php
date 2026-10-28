<?php

namespace App\Jobs;

use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Jobs\Middleware\PreventOverlappingImports;
use App\Listeners\FailQueuedJobOnFatalError;
use App\Models\DataSource;
use App\Services\ProductCatalog\ProductCatalogSync;
use App\Services\ProductCatalog\ProductCatalogSyncRuns;
use App\Services\ProductCatalog\ProductCatalogSyncTiming;
use App\Services\ShipmentImport\ImportRunRecorder;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

/**
 * One slice of a product catalog sync (`product-catalog-sync/02`): about a
 * minute of pages from where the run stands, after which it queues the next
 * slice at the back of the `imports` queue, so order imports queued
 * meanwhile run in between. Started by {@see ProductCatalogSync::start()},
 * which records the run this job carries on.
 */
class SyncProductCatalogJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = ProductCatalogSyncTiming::JOB_TIMEOUT;

    /** A real error is not retried: a second attempt hides the first one's error and spends API quota. */
    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    /**
     * @param  string  $runId  The run this slice carries on; it writes to no other.
     * @param  ?int  $userId  Who asked for the sync.
     */
    public function __construct(
        public int $dataSourceId,
        public string $runId,
        public ?int $userId = null,
    ) {
        $this->onConnection('imports')->onQueue('imports');
    }

    /**
     * One slice of a connection's sync at a time, should two workers take
     * them. A sync and an order import of the same connection take different
     * locks; on the one `imports` worker they take turns.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [PreventOverlappingImports::forProductCatalogSync($this->dataSourceId, ProductCatalogSyncTiming::LOCK_SECONDS)];
    }

    /**
     * How long this slice may wait in the queue, and behind another slice's
     * lock, before it gives up: as long as its run may go without saving a
     * page before it counts as interrupted. Bounded by time rather than
     * attempts, because waiting behind the lock spends an attempt a minute.
     */
    public function retryUntil(): ?DateTimeInterface
    {
        return now()->addSeconds(ProductCatalogSyncTiming::STALE_AFTER_SECONDS);
    }

    public function handle(ProductCatalogSync $sync, ProductCatalogSyncRuns $runs): void
    {
        $dataSource = DataSource::find($this->dataSourceId);

        if ($dataSource === null) {
            return;
        }

        if (! $dataSource->syncsProductCatalog()) {
            $runs->update($dataSource, $this->runId, fn (ProductCatalogSyncRun $run): ProductCatalogSyncRun => $run->failAtNextPage('The connection was deactivated, so the sync stopped. Products it had already saved are kept.'));

            return;
        }

        if ($sync->runSlice($dataSource, $this->runId)) {
            static::dispatch($this->dataSourceId, $this->runId, $this->userId);
        }
    }

    /**
     * Record the run as failed when this slice stopped without saying so
     * itself: a timeout, waiting too long, or — through
     * {@see FailQueuedJobOnFatalError} — a fatal error. Only this job's run,
     * and only while it is running: a finished run, or one that replaced it,
     * is left alone.
     */
    public function failed(?Throwable $exception): void
    {
        $dataSource = DataSource::find($this->dataSourceId);

        if ($dataSource === null) {
            return;
        }

        app(ProductCatalogSyncRuns::class)->update(
            $dataSource,
            $this->runId,
            fn (ProductCatalogSyncRun $run): ProductCatalogSyncRun => $run->failAtNextPage($this->reasonStopped($exception)),
        );
    }

    private function reasonStopped(?Throwable $exception): string
    {
        return match (true) {
            $exception instanceof FatalError && str_contains($exception->getMessage(), 'Allowed memory size') => 'The sync ran out of memory and stopped. Products it had already saved are kept.',
            $exception instanceof FatalError => 'The sync stopped on a server error. Products it had already saved are kept.',
            $exception instanceof TimeoutExceededException => 'Part of the sync took longer than '.(ProductCatalogSyncTiming::JOB_TIMEOUT / 60).' minutes and was stopped. Products it had already saved are kept.',
            $exception instanceof MaxAttemptsExceededException => 'The sync waited too long in the import queue and stopped. Products it had already saved are kept.',
            $exception !== null => 'The sync stopped: '.ImportRunRecorder::reasonFor($exception),
            default => 'The sync stopped before it finished.',
        };
    }
}
