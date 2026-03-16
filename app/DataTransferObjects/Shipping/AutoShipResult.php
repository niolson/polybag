<?php

namespace App\DataTransferObjects\Shipping;

readonly class AutoShipResult
{
    public function __construct(
        public bool $success,
        public ?ShipResponse $response = null,
        public ?RateResponse $selectedRate = null,
        public ?string $errorTitle = null,
        public ?string $errorMessage = null,
    ) {}

    public static function shipped(ShipResponse $response, RateResponse $rate): self
    {
        return new self(success: true, response: $response, selectedRate: $rate);
    }

    public static function failed(string $title, string $message): self
    {
        return new self(success: false, errorTitle: $title, errorMessage: $message);
    }

    public function summaryMessage(): string
    {
        if (! $this->success || ! $this->response) {
            return $this->errorMessage ?? 'Unknown error';
        }

        return "Tracking: {$this->response->trackingNumber} via {$this->response->carrier}"
            ." ({$this->selectedRate->serviceName}) - \$".number_format($this->response->cost, 2);
    }
}
