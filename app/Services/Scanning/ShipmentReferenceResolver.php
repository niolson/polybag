<?php

namespace App\Services\Scanning;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every Shipment with a given order reference. A reference is only unique
 * within one connection, so it may name several; callers ask the packer
 * rather than choose (ADR-0007, decision 2). A PolyBag code never comes here.
 */
class ShipmentReferenceResolver
{
    /** Enough to choose from; a reference shared more widely is a data problem. */
    private const int LIMIT = 25;

    /**
     * @return Collection<int, Shipment>
     */
    public function matching(string $reference): Collection
    {
        $reference = trim($reference);

        if ($reference === '') {
            return new Collection;
        }

        return Shipment::query()
            ->with(['client', 'dataSource'])
            ->where('shipment_reference', $reference)
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }
}
