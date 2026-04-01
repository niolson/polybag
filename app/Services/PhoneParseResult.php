<?php

namespace App\Services;

readonly class PhoneParseResult
{
    public function __construct(
        public ?string $phone,
        public ?string $e164,
        public ?string $extension,
        public ?string $error = null,
    ) {}

    public function isValid(): bool
    {
        return $this->e164 !== null;
    }
}
