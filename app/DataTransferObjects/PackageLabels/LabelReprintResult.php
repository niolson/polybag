<?php

namespace App\DataTransferObjects\PackageLabels;

use App\DataTransferObjects\PrintRequest;

final readonly class LabelReprintResult
{
    public function __construct(
        public bool $success,
        public ?PrintRequest $printRequest = null,
        public string $title = '',
        public string $message = '',
    ) {}

    public static function success(PrintRequest $printRequest, string $message, string $title = 'Label Sent to Printer'): self
    {
        return new self(
            success: true,
            printRequest: $printRequest,
            title: $title,
            message: $message,
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
