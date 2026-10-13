<?php

namespace App\Contracts;

use App\DataTransferObjects\ShipmentImport\SourceClosureResult;
use App\Models\DataSource;
use Illuminate\Support\Collection;

/**
 * A source whose import feed holds only open orders, and which can say what
 * became of an order that left it (`order-reconciliation` decision 2).
 *
 * An order cancelled or fulfilled elsewhere drops out of the feed, and its
 * Shipment would otherwise stay open here, where it can be packed and bought
 * for. A source implementing this looks up the orders the run did not fetch
 * and closes the Shipments the source has closed.
 */
interface ClosesRecordsClosedAtSource
{
    /**
     * Called once per import run, after every fetched row is written, and
     * only when the fetch completed: absence from a partial feed says nothing.
     *
     * A lookup that fails leaves its Shipments for the next run and is
     * reported in the result, not thrown.
     *
     * @param  Collection<int, array<string, mixed>>  $shipments  every row this run fetched
     */
    public function closeRecordsClosedAtSource(DataSource $dataSource, Collection $shipments): SourceClosureResult;
}
