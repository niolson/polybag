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
        /**
         * Parts of the catalog the read passed over, as the source names them
         * (`product-catalog-sync/04`), the first {@see MAX_UNREAD_PARTS} of them.
         *
         * @var list<string>
         */
        public array $unreadParts = [],
        /** How many parts were passed over in all. */
        public int $unreadCount = 0,
        /** Why, as the source put it. */
        public ?string $unreadReason = null,
    ) {}

    /** How many unread parts a run names; the rest are counted. */
    public const MAX_UNREAD_PARTS = 10;

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
            unreadParts: $this->unreadParts,
            unreadCount: $this->unreadCount,
            unreadReason: $this->unreadReason,
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
     * The run with one more page saved: its counts added, where the next
     * page starts, and any part of the catalog the read passed over.
     *
     * @param  list<string>  $unreadParts
     * @param  ?string  $unreadReason  why the source could not read them
     */
    public function withPage(int $created, int $updated, int $unchanged, ?string $nextCursor, array $unreadParts = [], ?string $unreadReason = null): self
    {
        return $this->copy([
            'progressAt' => CarbonImmutable::now(),
            'created' => $this->created + $created,
            'updated' => $this->updated + $updated,
            'unchanged' => $this->unchanged + $unchanged,
            'pages' => $this->pages + 1,
            'cursor' => $nextCursor,
            'unreadParts' => array_slice([...$this->unreadParts, ...$unreadParts], 0, self::MAX_UNREAD_PARTS),
            'unreadCount' => $this->unreadCount + count($unreadParts),
            'unreadReason' => $this->unreadReason ?? $unreadReason,
        ]);
    }

    /**
     * The run at the end of the catalog: complete, or incomplete when the
     * read passed over part of it, stopping at nothing but naming what it
     * could not read.
     */
    public function finishReading(): self
    {
        if ($this->unreadCount === 0) {
            return $this->finish(ProductCatalogSyncStatus::Complete);
        }

        $more = $this->unreadCount - count($this->unreadParts);

        return $this->finish(
            ProductCatalogSyncStatus::Incomplete,
            implode('; ', $this->unreadParts).($more > 0 ? "; and {$more} more" : ''),
            $this->unreadReason ?? 'Part of the catalog could not be read.',
        );
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
            'unread_parts' => $this->unreadParts,
            'unread_count' => $this->unreadCount,
            'unread_reason' => $this->unreadReason,
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
            unreadParts: is_array($data['unread_parts'] ?? null) ? array_values(array_filter($data['unread_parts'], is_string(...))) : [],
            unreadCount: (int) ($data['unread_count'] ?? 0),
            unreadReason: isset($data['unread_reason']) ? (string) $data['unread_reason'] : null,
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
            'unreadParts' => $this->unreadParts,
            'unreadCount' => $this->unreadCount,
            'unreadReason' => $this->unreadReason,
            ...$changes,
        ]);
    }
}
