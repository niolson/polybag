<?php

namespace App\DataTransferObjects\PackageLabels;

final readonly class LabelVoidResult
{
    public function __construct(
        public bool $success,
        public string $title,
        public string $message,
        public ?string $warning = null,
    ) {}

    /**
     * @param  string|null  $warning  something the void could not put right itself, for the operator to
     */
    public static function success(?string $message = null, ?string $warning = null): self
    {
        return new self(
            success: true,
            title: 'Label voided',
            message: $message ?? 'The label has been voided.',
            warning: $warning,
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
