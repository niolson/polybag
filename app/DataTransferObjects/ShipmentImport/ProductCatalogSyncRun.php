<?php

namespace App\DataTransferObjects\ShipmentImport;

use App\Enums\ProductCatalogSyncStatus;
use App\Jobs\SyncProductCatalogJob;
use Carbon\CarbonImmutable;

/**
 * A connection's last product catalog sync, as stored on the connection
 * (`product-catalog-sync/02`). Counts are kept up to date as each page
 * commits, so a run that fails partway reports what it saved.
 */
final readonly class ProductCatalogSyncRun
{
    public function __construct(
        public string $runId,
        public ProductCatalogSyncStatus $status,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt = null,
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public ?string $stoppedAt = null,
        public ?string $error = null,
    ) {}

    public static function start(string $runId): self
    {
        return new self($runId, ProductCatalogSyncStatus::Running, CarbonImmutable::now());
    }

    /** The run with one more page's Products counted. */
    public function withPage(int $created, int $updated, int $unchanged): self
    {
        return new self(
            $this->runId,
            $this->status,
            $this->startedAt,
            $this->finishedAt,
            $this->created + $created,
            $this->updated + $updated,
            $this->unchanged + $unchanged,
            $this->stoppedAt,
            $this->error,
        );
    }

    public function finish(ProductCatalogSyncStatus $status, ?string $stoppedAt = null, ?string $error = null): self
    {
        return new self(
            $this->runId,
            $status,
            $this->startedAt,
            CarbonImmutable::now(),
            $this->created,
            $this->updated,
            $this->unchanged,
            $stoppedAt,
            $error,
        );
    }

    public function isRunning(): bool
    {
        return $this->status === ProductCatalogSyncStatus::Running && ! $this->isInterrupted();
    }

    /**
     * Still marked running after its lock has expired: the worker running it
     * was killed outright, with no chance to record how it ended. A new sync
     * may start.
     */
    public function isInterrupted(): bool
    {
        return $this->status === ProductCatalogSyncStatus::Running
            && $this->startedAt->addSeconds(SyncProductCatalogJob::LOCK_SECONDS)->isPast();
    }

    public function label(): string
    {
        return $this->isInterrupted() ? 'Interrupted' : $this->status->label();
    }

    /**
     * @return array{run_id: string, status: string, started_at: string, finished_at: ?string, created: int, updated: int, unchanged: int, stopped_at: ?string, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt?->toIso8601String(),
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
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

        return new self(
            runId: (string) ($data['run_id'] ?? ''),
            status: $status,
            startedAt: CarbonImmutable::parse($data['started_at']),
            finishedAt: filled($data['finished_at'] ?? null) ? CarbonImmutable::parse($data['finished_at']) : null,
            created: (int) ($data['created'] ?? 0),
            updated: (int) ($data['updated'] ?? 0),
            unchanged: (int) ($data['unchanged'] ?? 0),
            stoppedAt: isset($data['stopped_at']) ? (string) $data['stopped_at'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }
}
