<?php

namespace App\Listeners;

use App\Support\FatalErrorHeadroom;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

/**
 * Turns a PHP fatal error in a queued job into an ordinary job failure
 * (`import-memory/02`).
 *
 * A fatal error, such as running out of memory, is not an exception: no
 * `catch`, `maxExceptions` or `failed()` sees it. The worker process dies, and
 * the job is reserved again once its `retry_after` passes, with nobody told.
 * This marks the job failed from a shutdown function instead, so it is not
 * retried and its `failed()` can say what happened.
 *
 * Laravel's own shutdown handler is registered at boot, so it runs first and
 * has already reported the fatal to the log and Sentry by the time this runs.
 * {@see FatalErrorHeadroom} runs before both, so neither runs out of memory
 * again and cancels the rest.
 *
 * A process killed outright (`SIGKILL`, or a worker timeout) runs no shutdown
 * function, and is not covered here.
 */
#[Singleton]
class FailQueuedJobOnFatalError
{
    private ?Job $runningJob = null;

    /** @var array<int, Lock> */
    private array $locksHeldByRunningJob = [];

    private bool $registeredShutdownFunction = false;

    /**
     * Release this lock if the running job dies of a fatal error, rather than
     * leave it held until it expires. Only this execution's hold is released:
     * {@see Lock::release()} checks the owner.
     */
    public function releaseIfTheJobDies(Lock $lock): void
    {
        $this->locksHeldByRunningJob[] = $lock;
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        $this->runningJob = $event->job;
        $this->locksHeldByRunningJob = [];

        if (! $this->registeredShutdownFunction) {
            register_shutdown_function(fn () => $this->failRunningJobOnFatalError());
            $this->registeredShutdownFunction = true;
        }
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->forgetRunningJob();
    }

    public function handleJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->forgetRunningJob();
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $this->forgetRunningJob();
    }

    public function failRunningJobOnFatalError(): void
    {
        $job = $this->runningJob;

        if ($job === null || ! FatalErrorHeadroom::lastErrorWasFatal()) {
            return;
        }

        $error = error_get_last();
        $locks = $this->locksHeldByRunningJob;
        $this->forgetRunningJob();

        // Raised once already, ahead of Laravel's report; that report may
        // have spent some of it.
        FatalErrorHeadroom::raise();

        try {
            // A fatal inside `DB::transaction()` leaves it open. Anything
            // written now — the failed job, its batch's count, the job's
            // notification — would be inside the dead transaction and vanish
            // when the process exits. `fail()` rolls back only for a timeout.
            $this->rollBackOpenTransactions();

            if (! $job->isDeleted() && ! $job->hasFailed()) {
                $job->fail(new FatalError($error['message'], 0, $error, 0));
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            $this->release($locks);
        }
    }

    private function forgetRunningJob(): void
    {
        $this->runningJob = null;
        $this->locksHeldByRunningJob = [];
    }

    /**
     * @param  array<int, Lock>  $locks
     */
    private function release(array $locks): void
    {
        foreach ($locks as $lock) {
            try {
                $lock->release();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function rollBackOpenTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }
    }
}
