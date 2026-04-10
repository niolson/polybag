<?php

namespace App\DataTransferObjects\Shipping;

use Carbon\CarbonImmutable;

readonly class ShipResponse
{
    /**
     * @param  array<string>  $appliedServices  Carrier-agnostic service codes actually sent to the carrier (e.g. 'saturday_delivery')
     */
    public function __construct(
        public bool $success,
        public ?string $trackingNumber = null,
        public ?float $cost = null,
        public ?string $carrier = null,
        public ?string $service = null,
        public ?string $labelData = null,
        public ?string $labelOrientation = null,
        public ?string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public ?CarbonImmutable $shipDate = null,
        public ?string $errorMessage = null,
        public array $appliedServices = [],
    ) {}

    /**
     * @param  array<string>  $appliedServices
     */
    public static function success(
        string $trackingNumber,
        float $cost,
        string $carrier,
        string $service,
        ?string $labelData = null,
        string $labelOrientation = 'portrait',
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
        ?CarbonImmutable $shipDate = null,
        array $appliedServices = [],
    ): self {
        return new self(
            success: true,
            trackingNumber: $trackingNumber,
            cost: $cost,
            carrier: $carrier,
            service: $service,
            labelData: $labelData,
            labelOrientation: $labelOrientation,
            labelFormat: $labelFormat,
            labelDpi: $labelDpi,
            shipDate: $shipDate,
            appliedServices: $appliedServices,
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
