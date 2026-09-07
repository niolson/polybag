<?php

namespace App\DataTransferObjects;

/**
 * A bounded sample of what a database DataSource's read queries actually return,
 * shown as raw source columns alongside the internal fields the configured field
 * mapping resolves them to. Rows are for display only — they are customer order
 * data and must never reach a log or the audit trail.
 */
final readonly class QueryPreviewResult
{
    /**
     * @param  list<array{raw: array<string, mixed>, mapped: array<string, mixed>}>  $shipments
     * @param  list<array{raw: array<string, mixed>, mapped: array<string, mixed>}>  $items
     * @param  array<string, string>  $errors  Query label => failure message.
     */
    public function __construct(
        public array $shipments,
        public array $items,
        public ?string $itemsReference,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
