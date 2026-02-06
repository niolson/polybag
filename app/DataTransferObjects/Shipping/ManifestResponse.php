<?php

namespace App\DataTransferObjects\Shipping;

readonly class ManifestResponse
{
    public function __construct(
        public bool $success,
        public ?string $manifestNumber = null,
        public ?string $image = null,
        public ?string $carrier = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(
        string $manifestNumber,
        string $carrier,
        ?string $image = null,
    ): self {
        return new self(
            success: true,
            manifestNumber: $manifestNumber,
            carrier: $carrier,
            image: $image,
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
