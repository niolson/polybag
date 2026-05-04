<?php

namespace App\DataTransferObjects\PackageShipping;

readonly class PackageAutoShippingRequest
{
    public function __construct(
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public ?int $userId = null,
        public bool $cleanupOnFailure = true,
    ) {}
}
