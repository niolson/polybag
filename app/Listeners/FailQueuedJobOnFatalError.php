<?php

namespace App\Listeners;

use App\Support\FatalErrorHeadroom;
use Illuminate\Container\Attributes\Singleton;
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

    private bool $registeredShutdownFunction = false;

    public function handleJobProcessing(JobProcessing $event): void
    {
        $this->runningJob = $event->job;

        if (! $this->registeredShutdownFunction) {
            register_shutdown_function(fn () => $this->failRunningJobOnFatalError());
            $this->registeredShutdownFunction = true;
        }
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->runningJob = null;
    }

    public function handleJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->runningJob = null;
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $this->runningJob = null;
    }

    public function failRunningJobOnFatalError(): void
    {
        $job = $this->runningJob;

        if ($job === null || ! FatalErrorHeadroom::lastErrorWasFatal()) {
            return;
        }

        $error = error_get_last();
        $this->runningJob = null;

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
