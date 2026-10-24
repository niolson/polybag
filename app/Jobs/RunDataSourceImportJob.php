<?php

namespace App\Jobs;

use App\Enums\Role;
use App\Listeners\FailQueuedJobOnFatalError;
use App\Models\DataSource;
use App\Models\User;
use App\Notifications\ImportCompleted;
use App\Notifications\ImportStopped;
use App\Services\SettingsService;
use App\Services\ShipmentImport\ImportRunRecorder;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

class RunDataSourceImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $dataSourceId,
        public int $userId,
        /** @var array<string, mixed> */
        public array $sourceOverrides = [],
    ) {
        $this->onConnection('imports')->onQueue('imports');
    }

    /**
     * Prevent concurrent imports of the same source (double-clicks, scheduler overlap).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("data-source-import:{$this->dataSourceId}"))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(SettingsService $settings): void
    {
        $source = DataSource::find($this->dataSourceId);

        if (! $source || ! $source->importsOrders()) {
            return;
        }

        if ($source->source_type === AmazonSource::class && ! $settings->get('require_mfa', false)) {
            User::find($this->userId)?->notify(
                new ImportCompleted([], $source->name, ['Amazon SP-API imports require Multi-Factor Authentication to be enabled. Enable it in App Settings → Security.'])
            );

            return;
        }

        if ($source->source_type === AmazonSource::class && blank($source->settings['marketplace_id'] ?? null)) {
            User::find($this->userId)?->notify(
                new ImportCompleted([], $source->name, ['Choose an Amazon marketplace before running imports.'])
            );

            return;
        }

        $result = ShipmentImportService::forRecord($source, $this->sourceOverrides)->import();

        // ImportRunRecorder already notifies all active admins when an import
        // finishes with errors; only the success confirmation is on us here.
        if ($result->hasErrors()) {
            return;
        }

        User::find($this->userId)?->notify(
            new ImportCompleted($result->toArray(), $source->name)
        );
    }

    /**
     * Tell the user who started the import, and the active admins, that it
     * stopped. Reached for an exception, a timeout, running out of attempts,
     * and — through {@see FailQueuedJobOnFatalError} — a fatal error, none of
     * which leave an {@see ImportCompleted} behind.
     */
    public function failed(?Throwable $exception): void
    {
        $source = DataSource::find($this->dataSourceId);
        $notification = new ImportStopped(
            $source->name ?? "Connection #{$this->dataSourceId}",
            self::reasonStopped($exception),
        );

        User::query()
            ->where(fn ($query) => $query
                ->whereKey($this->userId)
                ->orWhere(fn ($admins) => $admins->where('role', Role::Admin)->where('active', true)))
            ->get()
            ->each->notify($notification);
    }

    private static function reasonStopped(?Throwable $exception): string
    {
        return match (true) {
            $exception instanceof FatalError && str_contains($exception->getMessage(), 'Allowed memory size') => 'The import ran out of memory and stopped. Shipments it had already written are kept.',
            $exception instanceof FatalError => 'The import stopped on a server error. Shipments it had already written are kept.',
            $exception instanceof TimeoutExceededException => 'The import took longer than 30 minutes and was stopped. Shipments it had already written are kept.',
            $exception instanceof MaxAttemptsExceededException => 'Another import of this connection was still running, and this one stopped waiting for it.',
            $exception !== null => 'The import stopped: '.ImportRunRecorder::reasonFor($exception),
            default => 'The import stopped before it finished.',
        };
    }
}
