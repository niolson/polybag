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
    ) {}

    public static function start(string $runId): self
    {
        $now = CarbonImmutable::now();

        return new self($runId, ProductCatalogSyncStatus::Running, $now, $now);
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

    public function finish(ProductCatalogSyncStatus $status, ?string $stoppedAt = null, ?string $error = null): self
    {
        return $this->copy([
            'status' => $status,
            'finishedAt' => CarbonImmutable::now(),
            'stoppedAt' => $stoppedAt,
            'error' => $error,
        ]);
    }

    /** The run failed on the page after the last one it saved. */
    public function failAtNextPage(string $error): self
    {
        return $this->finish(ProductCatalogSyncStatus::Failed, 'page '.($this->pages + 1), $error);
    }

    /** Running, and not stalled. */
    public function isRunning(): bool
    {
        return $this->status === ProductCatalogSyncStatus::Running && ! $this->isInterrupted();
    }

    /**
     * Still marked running, but no page saved for longer than a slice and the
     * wait behind an order import could take: the job carrying it on was
     * lost. A new sync may start, and a late job of this run then stops.
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
            ...$changes,
        ]);
    }
}
