<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * A source that reads its feed a page at a time, so an import holds one page
 * of rows rather than the whole feed (`import-memory/01`).
 *
 * Re-pointing and closure have to see every record a run reads, and
 * re-pointing has to run before any write. So the import first reads every
 * record's identity, cheaply, and hands those identities to
 * {@see ReconcilesSupersededRecords} and {@see ClosesRecordsClosedAtSource}
 * in place of rows. Only then does it read the rows, writing each page before
 * it asks for the next.
 *
 * A source implementing this still implements
 * {@see DataSourceInterface::fetchShipments()} for callers that want the
 * whole set at once.
 */
interface ReadsInPages
{
    /**
     * Every record this run offers, as the source identifies it. Called once
     * per run, before {@see fetchShipmentPages()}; it also decides what the
     * pages read.
     *
     * @return Collection<int, mixed> identities the import passes on without reading
     */
    public function fetchIdentities(): Collection;

    /**
     * The mapped rows, a page at a time. Each page is written before the next
     * is read, and {@see DataSourceInterface::fetchShipmentItems()} answers
     * for the rows of the page in hand.
     *
     * A page that cannot be read throws, and the run stops reading.
     *
     * @return iterable<int, Collection<int, array<string, mixed>>>
     */
    public function fetchShipmentPages(): iterable;
}
