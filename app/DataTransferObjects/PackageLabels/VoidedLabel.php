<?php

namespace App\DataTransferObjects\PackageLabels;

use Carbon\CarbonImmutable;

/**
 * What a package's label looked like the instant before it was voided.
 *
 * Built from the database row under the void's own row lock, never from a model
 * instance: the instance the caller holds may predate a print acknowledgement or a
 * tracking refresh, and `label_printed_at` — the field that tells a number in a
 * database apart from a parcel with a dead label on it — is exactly the one most
 * likely to be stale there.
 */
final readonly class VoidedLabel
{
    public function __construct(
        public ?string $trackingNumber,
        public ?string $carrier,
        public ?int $normalizedCarrierId,
        public ?string $service,
        public ?string $serviceEvidence,
        public ?string $cost,
        public ?string $postageSource,
        public ?CarbonImmutable $labelPrintedAt,
        public ?CarbonImmutable $shippedAt,
        public ?CarbonImmutable $shipDate,
    ) {}

    /**
     * @param  object{tracking_number: ?string, carrier: ?string, normalized_carrier_id: int|string|null, service: ?string, service_evidence: ?string, cost: float|string|null, postage_source: ?string, label_printed_at: ?string, shipped_at: ?string, ship_date: ?string}  $row
     */
    public static function fromRow(object $row): self
    {
        return new self(
            trackingNumber: $row->tracking_number,
            carrier: $row->carrier,
            normalizedCarrierId: $row->normalized_carrier_id === null ? null : (int) $row->normalized_carrier_id,
            service: $row->service,
            serviceEvidence: $row->service_evidence,
            cost: $row->cost === null ? null : number_format((float) $row->cost, 2, '.', ''),
            postageSource: $row->postage_source,
            labelPrintedAt: filled($row->label_printed_at) ? CarbonImmutable::parse($row->label_printed_at) : null,
            shippedAt: filled($row->shipped_at) ? CarbonImmutable::parse($row->shipped_at) : null,
            shipDate: filled($row->ship_date) ? CarbonImmutable::parse($row->ship_date) : null,
        );
    }

    /**
     * @return array{tracking_number: ?string, carrier: ?string, normalized_carrier_id: ?int, service: ?string, service_evidence: ?string, cost: ?string, postage_source: ?string, label_printed_at: ?string, shipped_at: ?string, ship_date: ?string}
     */
    public function toArray(): array
    {
        return [
            'tracking_number' => $this->trackingNumber,
            'carrier' => $this->carrier,
            'normalized_carrier_id' => $this->normalizedCarrierId,
            'service' => $this->service,
            'service_evidence' => $this->serviceEvidence,
            'cost' => $this->cost,
            'postage_source' => $this->postageSource,
            'label_printed_at' => $this->labelPrintedAt?->toIso8601String(),
            'shipped_at' => $this->shippedAt?->toIso8601String(),
            'ship_date' => $this->shipDate?->toDateString(),
        ];
    }
}
