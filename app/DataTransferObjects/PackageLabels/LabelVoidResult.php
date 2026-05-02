<?php

namespace App\DataTransferObjects\PackageLabels;

final readonly class LabelVoidResult
{
    public function __construct(
        public bool $success,
        public string $title,
        public string $message,
    ) {}

    public static function success(?string $message = null): self
    {
        return new self(
            success: true,
            title: 'Label voided',
            message: $message ?? 'The label has been voided.',
        );
    }

    public static function failure(string $title, string $message): self
    {
        return new self(
            success: false,
            title: $title,
            message: $message,
        );
    }
}
