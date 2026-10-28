<?php

namespace App\DataTransferObjects\ShipmentImport;

use App\Enums\ProductCatalogSyncStatus;
use App\Services\ProductCatalog\ProductCatalogSyncTiming;
use Carbon\CarbonImmutable;

/**
 * A connection's last product catalog sync, as stored on the connection
 * (`product-catalog-sync/02`).
 *
 * A sync runs as a chain of short jobs. The run is their shared state: each
 * page commits with the counts, the page number and the cursor the next page
 * starts from, so the next job resumes where the last stopped and a failure
 * reports what was saved and which page it stopped at.
 */
final readonly class ProductCatalogSyncRun
{
    public function __construct(
        public string $runId,
        public ProductCatalogSyncStatus $status,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $progressAt,
        public ?CarbonImmutable $finishedAt = null,
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $pages = 0,
        public ?string $cursor = null,
        public ?string $stoppedAt = null,
        public ?string $error = null,
        /** Set on a failure the next sync cannot resume from, such as a cursor the source refused. */
        public bool $startOver = false,
        /** The page this run picked up at, carrying on a run that failed or was interrupted. */
        public ?int $resumedFromPage = null,
    ) {}

    public static function start(string $runId): self
    {
        $now = CarbonImmutable::now();

        return new self($runId, ProductCatalogSyncStatus::Running, $now, $now);
    }

    /**
     * A new run that carries on from this one: its cursor, pages and counts.
     */
    public function resumeAs(string $runId): self
    {
        $now = CarbonImmutable::now();

        return new self(
            runId: $runId,
            status: ProductCatalogSyncStatus::Running,
            startedAt: $now,
            progressAt: $now,
            created: $this->created,
            updated: $this->updated,
            unchanged: $this->unchanged,
            pages: $this->pages,
            cursor: $this->cursor,
            resumedFromPage: $this->pages + 1,
        );
    }

    /**
     * Whether a new sync may carry on from this run rather than start over:
     * it failed, or was interrupted, partway through the catalog, and not in
     * a way that rules out its cursor.
     */
    public function canResume(): bool
    {
        $stopped = ($this->status === ProductCatalogSyncStatus::Failed && ! $this->startOver) || $this->isInterrupted();

        return $stopped && $this->cursor !== null;
    }

    /**
     * The run with one more page saved: its counts added, and where the next
     * page starts.
     */
    public function withPage(int $created, int $updated, int $unchanged, ?string $nextCursor): self
    {
        return $this->copy([
            'progressAt' => CarbonImmutable::now(),
            'created' => $this->created + $created,
            'updated' => $this->updated + $updated,
            'unchanged' => $this->unchanged + $unchanged,
            'pages' => $this->pages + 1,
            'cursor' => $nextCursor,
        ]);
    }

    public function finish(ProductCatalogSyncStatus $status, ?string $stoppedAt = null, ?string $error = null, bool $startOver = false): self
    {
        return $this->copy([
            'status' => $status,
            'finishedAt' => CarbonImmutable::now(),
            'stoppedAt' => $stoppedAt,
            'error' => $error,
            'startOver' => $startOver,
        ]);
    }

    /**
     * The run failed on the page after the last one it saved. With
     * `$startOver`, the next sync reads from the first page.
     */
    public function failAtNextPage(string $error, bool $startOver = false): self
    {
        return $this->finish(ProductCatalogSyncStatus::Failed, 'page '.($this->pages + 1), $error, $startOver);
    }

    /** Running, and not stalled. */
    public function isRunning(): bool
    {
        return $this->status === ProductCatalogSyncStatus::Running && ! $this->isInterrupted();
    }

    /**
     * Still marked running, but no page saved for longer than a slice could
     * wait in a busy import queue: the job carrying it on was lost. A new
     * sync may start, resuming from this run's cursor, and a late job of this
     * run then stops.
     */
    public function isInterrupted(): bool
    {
        return $this->status === ProductCatalogSyncStatus::Running
            && $this->progressAt->addSeconds(ProductCatalogSyncTiming::STALE_AFTER_SECONDS)->isPast();
    }

    public function label(): string
    {
        return $this->isInterrupted() ? 'Interrupted' : $this->status->label();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'started_at' => $this->startedAt->toIso8601String(),
            'progress_at' => $this->progressAt->toIso8601String(),
            'finished_at' => $this->finishedAt?->toIso8601String(),
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'pages' => $this->pages,
            'cursor' => $this->cursor,
            'stopped_at' => $this->stoppedAt,
            'error' => $this->error,
            'start_over' => $this->startOver,
            'resumed_from_page' => $this->resumedFromPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $status = ProductCatalogSyncStatus::tryFrom((string) ($data['status'] ?? ''));

        if ($status === null || blank($data['started_at'] ?? null)) {
            return null;
        }

        $startedAt = CarbonImmutable::parse($data['started_at']);

        return new self(
            runId: (string) ($data['run_id'] ?? ''),
            status: $status,
            startedAt: $startedAt,
            progressAt: filled($data['progress_at'] ?? null) ? CarbonImmutable::parse($data['progress_at']) : $startedAt,
            finishedAt: filled($data['finished_at'] ?? null) ? CarbonImmutable::parse($data['finished_at']) : null,
            created: (int) ($data['created'] ?? 0),
            updated: (int) ($data['updated'] ?? 0),
            unchanged: (int) ($data['unchanged'] ?? 0),
            pages: (int) ($data['pages'] ?? 0),
            cursor: isset($data['cursor']) ? (string) $data['cursor'] : null,
            stoppedAt: isset($data['stopped_at']) ? (string) $data['stopped_at'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            startOver: (bool) ($data['start_over'] ?? false),
            resumedFromPage: isset($data['resumed_from_page']) ? (int) $data['resumed_from_page'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function copy(array $changes): self
    {
        return new self(...[
            'runId' => $this->runId,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'progressAt' => $this->progressAt,
            'finishedAt' => $this->finishedAt,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'pages' => $this->pages,
            'cursor' => $this->cursor,
            'stoppedAt' => $this->stoppedAt,
            'error' => $this->error,
            'startOver' => $this->startOver,
            'resumedFromPage' => $this->resumedFromPage,
            ...$changes,
        ]);
    }
}
