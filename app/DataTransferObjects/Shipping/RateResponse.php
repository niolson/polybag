<?php

namespace App\DataTransferObjects\Shipping;

readonly class RateResponse
{
    public function __construct(
        public string $carrier,
        public string $serviceCode,
        public string $serviceName,
        public float $price,
        public ?string $deliveryCommitment = null,
        public ?string $deliveryDate = null,
        public ?string $transitTime = null,
        public array $metadata = [],
    ) {}

    /**
     * Convert to array format for Livewire serialization.
     *
     * @return array{carrier: string, serviceCode: string, serviceName: string, price: float, deliveryCommitment: ?string, deliveryDate: ?string, transitTime: ?string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'carrier' => $this->carrier,
            'serviceCode' => $this->serviceCode,
            'serviceName' => $this->serviceName,
            'price' => $this->price,
            'deliveryCommitment' => $this->deliveryCommitment,
            'deliveryDate' => $this->deliveryDate,
            'transitTime' => $this->transitTime,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create a RateResponse from an array (lossless round-trip from toArray).
     *
     * @param  array{carrier: string, serviceCode: string, serviceName: string, price: float, deliveryCommitment: ?string, deliveryDate: ?string, transitTime: ?string, metadata: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            carrier: $data['carrier'],
            serviceCode: $data['serviceCode'],
            serviceName: $data['serviceName'],
            price: (float) $data['price'],
            deliveryCommitment: $data['deliveryCommitment'] ?? null,
            deliveryDate: $data['deliveryDate'] ?? null,
            transitTime: $data['transitTime'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function formLabel(): string
    {
        return "[{$this->carrier}] {$this->serviceName}";
    }

    public function formDescription(): string
    {
        $price = number_format($this->price, 2);
        $detail = $this->carrier === 'USPS'
            ? ($this->deliveryCommitment ?? '')
            : ($this->transitTime ?? '');

        return '$' . $price . ($detail ? " - {$detail}" : '');
    }
}
