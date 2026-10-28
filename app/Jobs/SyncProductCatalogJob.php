<?php

namespace App\Jobs;

use App\Enums\ProductCatalogSyncStatus;
use App\Jobs\Middleware\PreventOverlappingImports;
use App\Listeners\FailQueuedJobOnFatalError;
use App\Models\DataSource;
use App\Services\ProductCatalog\ProductCatalogSync;
use App\Services\ShipmentImport\ImportRunRecorder;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Str;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

/**
 * Sync a connection's product catalog into Products (`product-catalog-sync/02`),
 * configured as {@see RunDataSourceImportJob} is: a 30-minute timeout that
 * fails the job, a per-connection lock that expires, waiting behind another
 * sync bounded by time rather than attempts, and one real attempt.
 */
class SyncProductCatalogJob implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 1800;

    /** How long the overlap lock outlives a worker killed mid-run. */
    public const LOCK_SECONDS = self::TIMEOUT + 60;

    public int $timeout = self::TIMEOUT;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    /**
     * Names the run this job records, so a failure while waiting behind
     * another sync never marks that one failed.
     */
    public string $runId;

    /**
     * @param  ?int  $userId  Who asked for the sync.
     */
    public function __construct(
        public int $dataSourceId,
        public ?int $userId = null,
    ) {
        $this->runId = (string) Str::uuid();
        $this->onConnection('imports')->onQueue('imports');
    }

    /**
     * One sync of a connection at a time. Its lock is not the import's, so
     * a sync and an order import of the same connection may overlap.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [PreventOverlappingImports::forProductCatalogSync($this->dataSourceId, self::LOCK_SECONDS)];
    }

    /**
     * How long a sync may wait behind another sync of the same connection:
     * the lock's expiry plus a margin. Waiting is not a failure.
     */
    public function retryUntil(): ?DateTimeInterface
    {
        return now()->addSeconds(self::LOCK_SECONDS + 240);
    }

    public function handle(ProductCatalogSync $sync): void
    {
        $dataSource = DataSource::find($this->dataSourceId);

        if (! $dataSource || ! $dataSource->syncsProductCatalog()) {
            return;
        }

        $sync->run($dataSource, $this->runId);
    }

    /**
     * Record the run as failed when it stopped without saying so itself: a
     * timeout, or — through {@see FailQueuedJobOnFatalError} — a fatal error.
     * A run this job never started, because it was still waiting, is left
     * alone.
     */
    public function failed(?Throwable $exception): void
    {
        $dataSource = DataSource::find($this->dataSourceId);
        $run = $dataSource?->productCatalogSyncRun();

        if ($dataSource === null || $run === null || $run->runId !== $this->runId || $run->status !== ProductCatalogSyncStatus::Running) {
            return;
        }

        $dataSource->recordProductCatalogSyncRun($run->finish(
            ProductCatalogSyncStatus::Failed,
            error: $this->reasonStopped($exception),
        ));
    }

    private function reasonStopped(?Throwable $exception): string
    {
        return match (true) {
            $exception instanceof FatalError && str_contains($exception->getMessage(), 'Allowed memory size') => 'The sync ran out of memory and stopped. Products it had already saved are kept.',
            $exception instanceof FatalError => 'The sync stopped on a server error. Products it had already saved are kept.',
            $exception instanceof TimeoutExceededException => 'The sync took longer than 30 minutes and was stopped. Products it had already saved are kept.',
            $exception !== null => 'The sync stopped: '.ImportRunRecorder::reasonFor($exception),
            default => 'The sync stopped before it finished.',
        };
    }
}
