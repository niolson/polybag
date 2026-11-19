<?php

namespace App\DataTransferObjects;

use App\Models\LabelBatch;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * What Batch Ship started, and the Shipments it skipped while drafting their
 * Packages: a Shipment that passed validation can still be taken by an
 * overlapping run before its draft commits (`release-polish/06`).
 */
readonly class BatchCreationResult
{
    public function __construct(
        /** No batch when every Shipment was skipped. */
        public ?LabelBatch $batch,
        /** @var Collection<int, array{shipment: Shipment, reason: string}> */
        public Collection $skipped,
    ) {}
}
