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
     * @param  list<array{raw: array<string, mixed>, mapped: array<string, mixed>}>  $statusRows  the status query's rows, "mapped" being the columns it is read by
     */
    public function __construct(
        public array $shipments,
        public array $items,
        public ?string $itemsReference,
        public array $errors,
        public array $statusRows = [],
        public ?string $statusReference = null,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Plain-array form for the cache, which will not unserialize objects
     * (`serializable_classes` is off).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data  a value produced by {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['shipments'],
            $data['items'],
            $data['itemsReference'],
            $data['errors'],
            $data['statusRows'],
            $data['statusReference'],
        );
    }
}
