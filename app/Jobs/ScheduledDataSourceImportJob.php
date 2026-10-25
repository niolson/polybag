<?php

namespace App\Jobs;

use DateTimeInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Throwable;

/**
 * An import the scheduler started. Unique until it starts, so a worker that
 * falls behind holds one waiting import per connection rather than one per
 * scheduler tick.
 */
class ScheduledDataSourceImportJob extends RunDataSourceImportJob implements ShouldBeUniqueUntilProcessing
{
    /**
     * How long the uniqueness lock may outlive a job that never starts, such
     * as one lost from the queue. Laravel releases it only when processing
     * starts; without an expiry, a job that never got that far would keep
     * every later tick from queuing this connection's import.
     */
    public int $uniqueFor = 3600;

    public function __construct(int $dataSourceId)
    {
        parent::__construct($dataSourceId, null);
    }

    public function uniqueId(): string
    {
        return (string) $this->dataSourceId;
    }

    /**
     * No deadline. A scheduled run never waits on the overlap lock — it is
     * skipped — so a deadline would only fail a run that sat in a backlog,
     * before processing could release its uniqueness lock.
     */
    public function retryUntil(): ?DateTimeInterface
    {
        return null;
    }

    /**
     * Laravel releases a `ShouldBeUniqueUntilProcessing` lock when processing
     * starts, and so leaves it alone on failure. A job that failed before it
     * started still holds it, so release it here. Releasing after a run that
     * did start can at most let one more scheduled run queue.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            parent::failed($exception);
        } finally {
            (new UniqueLock(app(Cache::class)))->release($this);
        }
    }
}
