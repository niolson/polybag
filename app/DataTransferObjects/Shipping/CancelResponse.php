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

    /**
     * A 2xx whose body does not say whether the label was voided. Never read
     * as a success: a void is safe to retry, but a wrong success returns the
     * Package to unshipped while its Label is live, and re-shipping it buys a
     * second label.
     */
    public static function unreadable(string $carrier): self
    {
        return self::failure("{$carrier}'s reply to the void could not be read, so the label may still be live. Check {$carrier}'s site before voiding again.");
    }
}
