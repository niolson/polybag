<?php

namespace App\DataTransferObjects\PackageShipping;

use App\DataTransferObjects\PrintRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipResponse;

readonly class PackageShippingResult
{
    public function __construct(
        public bool $success,
        public ?string $title = null,
        public ?string $message = null,
        public ?ShipResponse $response = null,
        public ?RateResponse $selectedRate = null,
        public ?PrintRequest $printRequest = null,
        public bool $requiresCustomsWeightOverride = false,
        public bool $leavePackageIntact = false,
    ) {}

    public static function shipped(ShipResponse $response, RateResponse $rate): self
    {
        return new self(
            success: true,
            title: 'Package Shipped',
            message: "Tracking: {$response->trackingNumber}",
            response: $response,
            selectedRate: $rate,
            printRequest: $response->labelData ? PrintRequest::fromShipResponse($response) : null,
        );
    }

    public static function failed(string $title, string $message): self
    {
        return new self(success: false, title: $title, message: $message);
    }

    public static function customsWeightOverrideRequired(): self
    {
        return new self(
            success: false,
            title: 'Customs Weight Mismatch',
            message: 'Customs item weights exceed the package weight. Please review and confirm before shipping.',
            requiresCustomsWeightOverride: true,
        );
    }

    public static function stateConflict(string $message): self
    {
        return new self(success: false, title: 'Package State Changed', message: $message, leavePackageIntact: true);
    }

    public function summaryMessage(): string
    {
        if (! $this->success || ! $this->response) {
            return $this->message ?? 'Unknown error';
        }

        if (! $this->selectedRate) {
            return "Tracking: {$this->response->trackingNumber}";
        }

        return "Tracking: {$this->response->trackingNumber} via {$this->response->carrier}"
            ." ({$this->selectedRate->serviceName}) - \$".number_format($this->response->cost, 2);
    }
}
