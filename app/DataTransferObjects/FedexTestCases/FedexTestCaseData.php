<?php

namespace App\DataTransferObjects\FedexTestCases;

readonly class FedexTestCaseData
{
    /**
     * @param  array<int, string>  $notes
     * @param  array<string, mixed>  $request
     */
    public function __construct(
        public string $id,
        public string $description,
        public string $requestType,
        public bool $supported = true,
        public ?string $skipReason = null,
        public array $notes = [],
        public array $request = [],
        public ?string $api = null,
        public ?string $endpoint = null,
        public ?string $labelFormat = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            requestType: (string) ($data['request_type'] ?? self::mapLegacyRequestType((string) ($data['api'] ?? 'Ship API'))),
            supported: (bool) ($data['supported'] ?? true),
            skipReason: $data['skip_reason'] ?? null,
            notes: array_values(array_filter($data['notes'] ?? [], fn (mixed $note): bool => is_string($note))),
            request: is_array($data['request'] ?? null) ? $data['request'] : [],
            api: $data['api'] ?? null,
            endpoint: $data['endpoint'] ?? null,
            labelFormat: $data['label_format'] ?? $data['labelFormat'] ?? null,
        );
    }

    private static function mapLegacyRequestType(string $api): string
    {
        return $api === 'Freight LTL API' ? 'create_freight_shipment' : 'create_shipment';
    }
}
