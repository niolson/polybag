<?php

namespace App\Enums;

use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;

/**
 * How a connection's last product catalog sync stands (`product-catalog-sync/02`).
 *
 * *Interrupted* is not stored: it is a run still marked running that has
 * saved no page for too long, read off {@see ProductCatalogSyncRun::isInterrupted()}.
 */
enum ProductCatalogSyncStatus: string
{
    case Running = 'running';
    case Complete = 'complete';
    /**
     * Amazon (`product-catalog-sync/04`): the run read all it could, but a
     * one-second date window it could not split left listings unread.
     */
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
