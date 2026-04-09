<?php

namespace App\DataTransferObjects\Tracking;

use Carbon\CarbonImmutable;

readonly class TrackingEventData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?CarbonImmutable $timestamp,
        public ?string $location,
        public string $description,
        public ?string $statusCode = null,
        public ?string $status = null,
        public array $raw = [],
    ) {}

    /**
     * @return array{timestamp: ?string, location: ?string, description: string, status_code: ?string, status: ?string, raw: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp?->toIso8601String(),
            'location' => $this->location,
            'description' => $this->description,
            'status_code' => $this->statusCode,
            'status' => $this->status,
            'raw' => $this->raw,
        ];
    }

    /**
     * @param  array{timestamp: ?string, location: ?string, description: string, status_code?: ?string, status?: ?string, raw?: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            timestamp: filled($data['timestamp'] ?? null) ? CarbonImmutable::parse($data['timestamp']) : null,
            location: $data['location'] ?? null,
            description: $data['description'],
            statusCode: $data['status_code'] ?? null,
            status: $data['status'] ?? null,
            raw: $data['raw'] ?? [],
        );
    }
}
