<?php

namespace App\DataTransferObjects\PostageSources;

use App\Enums\OffAmazonShippingStatus;

/**
 * What checking an Amazon connection for off-Amazon Amazon Shipping found, and
 * the sentence to show the operator about it.
 */
readonly class OffAmazonShippingCheckResult
{
    public function __construct(
        public OffAmazonShippingStatus $status,
        public string $message,
    ) {}

    public function isEnabled(): bool
    {
        return $this->status === OffAmazonShippingStatus::Enabled;
    }
}
