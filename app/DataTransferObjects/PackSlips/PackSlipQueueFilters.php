<?php

namespace App\DataTransferObjects\PackSlips;

/**
 * The filters a Shipper narrows the pack slip queue with. Null means no filter.
 */
final readonly class PackSlipQueueFilters
{
    public function __construct(
        public ?int $clientId = null,
        public ?int $channelId = null,
        public ?int $shippingMethodId = null,
    ) {}
}
