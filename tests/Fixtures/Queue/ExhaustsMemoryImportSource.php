<?php

namespace Tests\Fixtures\Queue;

use App\Contracts\DataSourceInterface;
use Illuminate\Support\Collection;

/**
 * An import source whose read runs out of memory inside a database
 * transaction, as an import does when a batch write outgrows the limit.
 */
class ExhaustsMemoryImportSource implements DataSourceInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = []) // @phpstan-ignore constructor.unusedParameter
    {}

    public function fetchShipments(): Collection
    {
        ExhaustsMemory::insideTransaction();
    }

    public function fetchShipmentItems(string $sourceRecordId): Collection
    {
        return collect();
    }

    public function validateConfiguration(): void {}

    public function getFieldMapping(): array
    {
        return [];
    }

    public function markExported(string $sourceRecordId): bool
    {
        return false;
    }
}
