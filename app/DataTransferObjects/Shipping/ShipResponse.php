<?php

namespace App\DataTransferObjects\Shipping;

readonly class ShipResponse
{
    public function __construct(
        public bool $success,
        public ?string $trackingNumber = null,
        public ?float $cost = null,
        public ?string $carrier = null,
        public ?string $service = null,
        public ?string $labelData = null,
        public ?string $labelOrientation = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(
        string $trackingNumber,
        float $cost,
        string $carrier,
        string $service,
        ?string $labelData = null,
        string $labelOrientation = 'portrait',
    ): self {
        return new self(
            success: true,
            trackingNumber: $trackingNumber,
            cost: $cost,
            carrier: $carrier,
            service: $service,
            labelData: $labelData,
            labelOrientation: $labelOrientation,
        );
    }

    public static function failure(string $errorMessage): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
        );
    }
}
