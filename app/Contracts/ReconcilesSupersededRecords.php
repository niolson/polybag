<?php

namespace App\Contracts;

use App\Models\DataSource;
use Illuminate\Support\Collection;

/**
 * A source whose records can be replaced upstream by new ones carrying a
 * different identifier, for work PolyBag has already imported.
 *
 * `source_record_id` is the whole of a shipment's identity at import time, so
 * a source that re-keys a record hands the import work it has never seen and
 * a second shipment is created for goods that already have one. A source that
 * can recognise its own replacements says so by implementing this, and is
 * given the chance to re-point the shipments it already owns before the batch
 * write keys off the new identifier.
 */
interface ReconcilesSupersededRecords
{
    /**
     * Re-point shipments whose source record has been replaced upstream.
     *
     * Called once per import run with the complete fetched set — whether the
     * record a shipment currently names is still on offer is only answerable
     * with all of them in hand, never a chunk at a time.
     *
     * Implementations must fail toward leaving the shipment alone: a record
     * wrongly imported twice is visible and can be voided, where a shipment
     * wrongly re-pointed at another order's work loses goods silently.
     *
     * @param  Collection<int, array<string, mixed>>  $shipments  mapped source rows
     * @return int how many shipments were re-pointed
     */
    public function reconcileSupersededRecords(DataSource $dataSource, Collection $shipments): int;
}
