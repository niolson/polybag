<?php

namespace App\DataTransferObjects\Shipping;

readonly class LabelResult
{
    public function __construct(
        public bool $success,
        public ?ShipResponse $response = null,
        public ?RateResponse $selectedRate = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(ShipResponse $response, RateResponse $rate): self
    {
        return new self(
            success: true,
            response: $response,
            selectedRate: $rate,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            errorMessage: $message,
        );
    }
}
