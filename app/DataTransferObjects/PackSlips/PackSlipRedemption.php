<?php

namespace App\DataTransferObjects\PackSlips;

final readonly class PackSlipRedemption
{
    /**
     * @param  int  $recorded  Shipments whose latest print is now this receipt's
     * @param  int  $skipped  Shipments shipped, deleted, or already holding a newer print
     */
    public function __construct(
        public int $recorded,
        public int $skipped,
    ) {}
}
