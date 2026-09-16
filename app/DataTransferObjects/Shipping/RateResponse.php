<?php

namespace App\DataTransferObjects\Shipping;

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\Models\ShippingOffer;
use App\Services\RateSelector;
use Carbon\Carbon;

readonly class RateResponse
{
    public PackagingRequirement $packagingRequirement;

    /**
     * @param  string|null  $offerId  The opaque identifier of the {@see ShippingOffer} backing this rate, when the source issued one. Everything that can actually buy the label — tokens, source instance, environment, expiry — stays in that row; this is the only part of it that may cross into browser state. ADR-0002 decision 4.
     * @param  ObservedServiceIdentity|null  $observedService  Which discovered service this is an offer of, for the sources that discover one. Null means an authored `CarrierService` quoted from a carrier account, which is every rate that existed before discovery did. A source whose catalog is discovered — Amazon Buy Shipping — must set it: {@see RateSelector::selectBest()} is what decides whether automation may buy this, and it has no other way to ask (ADR-0003 decision 4).
     * @param  PackagingRequirement|null  $packagingRequirement  Which carrier packaging this rate is valid in (ADR-0005 decision 3). Defaults to the shipper's own packaging so that hand-built rates in tests compile unchanged, but every adapter sets it explicitly: the adapter is the only party that knows, and a reader of an adapter should see the decision being made rather than a default being taken.
     */
    public function __construct(
        public string $carrier,
        public string $serviceCode,
        public string $serviceName,
        public float $price,
        public ?string $deliveryCommitment = null,
        public ?string $deliveryDate = null,
        public ?string $transitTime = null,
        public array $metadata = [],
        public bool $priceUnknown = false,
        public ?string $offerId = null,
        public ?ObservedServiceIdentity $observedService = null,
        ?PackagingRequirement $packagingRequirement = null,
    ) {
        $this->packagingRequirement = $packagingRequirement ?? PackagingRequirement::shipperPackaging();
    }

    /**
     * Convert to array format for Livewire serialization.
     *
     * @return array{carrier: string, serviceCode: string, serviceName: string, price: float, deliveryCommitment: ?string, deliveryDate: ?string, transitTime: ?string, metadata: array<string, mixed>, priceUnknown: bool, offerId: ?string, observedService: ?array{source: string, environment: string, externalCarrierId: string, externalServiceId: string}, packagingRequirement: array{kind: string, packagings: list<string>}}
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
            'priceUnknown' => $this->priceUnknown,
            'offerId' => $this->offerId,
            'observedService' => $this->observedService?->toArray(),
            'packagingRequirement' => $this->packagingRequirement->toArray(),
        ];
    }

    /**
     * Create a RateResponse from an array (lossless round-trip from toArray).
     *
     * A missing `packagingRequirement` key — an array serialized before the
     * requirement existed — reads as the shipper's own packaging, which is the
     * safe direction: it accepts nothing a carrier supplies.
     *
     * @param  array{carrier: string, serviceCode: string, serviceName: string, price: float, deliveryCommitment: ?string, deliveryDate: ?string, transitTime: ?string, metadata?: array<string, mixed>, priceUnknown?: bool, offerId?: ?string, observedService?: ?array{source: string, environment: string, externalCarrierId: string, externalServiceId: string}, packagingRequirement?: ?array{kind?: string, packagings?: list<string>}}  $data
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
            priceUnknown: (bool) ($data['priceUnknown'] ?? false),
            offerId: $data['offerId'] ?? null,
            observedService: isset($data['observedService'])
                ? ObservedServiceIdentity::fromArray($data['observedService'])
                : null,
            packagingRequirement: isset($data['packagingRequirement'])
                ? PackagingRequirement::fromArray($data['packagingRequirement'])
                : PackagingRequirement::shipperPackaging(),
        );
    }

    public function formLabel(): string
    {
        return "[{$this->carrier}] {$this->serviceName}";
    }

    public function parsedDeliveryDate(): ?Carbon
    {
        if (! $this->deliveryDate) {
            return null;
        }

        try {
            return Carbon::parse($this->deliveryDate);
        } catch (\Exception) {
            return null;
        }
    }

    public function formDescription(): string
    {
        // Carriers that expose no rate API (Shopify Shipping) price the label at
        // purchase time; showing "$0.00" would read as a free label.
        if ($this->priceUnknown) {
            $detail = $this->deliveryCommitment ?? $this->transitTime;

            return 'Price set at purchase'.($detail ? " — {$detail}" : '');
        }

        $price = number_format($this->price, 2);

        // Show actual delivery date when available
        $parsed = $this->parsedDeliveryDate();
        if ($parsed) {
            $formatted = $parsed->format('D, M j');

            return '$'.$price.' — Delivers '.$formatted;
        }

        // Fall back to commitment name or transit time
        $detail = $this->carrier === 'USPS'
            ? ($this->deliveryCommitment ?? '')
            : ($this->transitTime ?? '');

        return '$'.$price.($detail ? " — {$detail}" : '');
    }
}
