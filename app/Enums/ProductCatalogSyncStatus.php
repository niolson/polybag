<?php

namespace App\Enums;

use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;

/**
 * How a connection's last product catalog sync stands (`product-catalog-sync/02`).
 *
 * *Interrupted* is not stored: it is a run still marked running after its
 * lock expired, read off {@see ProductCatalogSyncRun::isInterrupted()}.
 */
enum ProductCatalogSyncStatus: string
{
    case Running = 'running';
    case Complete = 'complete';
    /** The source read everything it could, but could not list part of the catalog. */
    case Incomplete = 'incomplete';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Complete => 'Complete',
            self::Incomplete => 'Incomplete',
            self::Failed => 'Failed',
        };
    }
}
