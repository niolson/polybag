<?php

namespace App\DataTransferObjects\PackSlips;

use InvalidArgumentException;

/**
 * The Shipments to put on paper, in the order they come off the printer.
 *
 * A tote code is given only when the run comes from a pick batch; without one the
 * slip has no tote row.
 */
final readonly class PackSlipRun
{
    /**
     * @param  list<int>  $shipmentIds  in print order
     * @param  array<int, string>  $toteCodes  keyed by Shipment ID
     */
    public function __construct(
        public array $shipmentIds,
        public array $toteCodes = [],
        public string $title = 'Pack Slips',
    ) {
        if ($shipmentIds !== array_values(array_unique($shipmentIds))) {
            throw new InvalidArgumentException('A pack slip run lists each Shipment once, as a list.');
        }
    }

    public static function forShipment(int $shipmentId): self
    {
        return new self([$shipmentId], title: 'Pack Slip');
    }

    /**
     * @return list<self>
     */
    public function chunk(int $size): array
    {
        return array_map(
            fn (array $ids): self => new self(
                $ids,
                array_intersect_key($this->toteCodes, array_flip($ids)),
                $this->title,
            ),
            array_chunk($this->shipmentIds, $size),
        );
    }

    public function count(): int
    {
        return count($this->shipmentIds);
    }
}
