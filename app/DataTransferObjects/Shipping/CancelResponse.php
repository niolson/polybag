<?php

namespace App\DataTransferObjects\Shipping;

readonly class CancelResponse
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
    ) {}

    public static function success(?string $message = null): self
    {
        return new self(
            success: true,
            message: $message,
        );
    }

    public static function failure(string $message): self
    {
        return new self(
            success: false,
            message: $message,
        );
    }
}
