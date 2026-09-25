<?php

namespace App\DataTransferObjects\Shipping;

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierService;
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
     * @param  int|null  $carrierAccountId  The {@see CarrierAccount} a direct adapter quoted this on: the one rate shopping handed it, or else the one `ResolvesCarrierAccount` resolved. Recorded on the offer the rate service issues for the rate, so the purchase can refuse when the account that would buy is no longer the one that quoted. Null for a rate resold through a channel — an Amazon offer names a data source instead — and for a fake adapter with no account to resolve.
     * @param  int|null  $carrierServiceId  The {@see CarrierService} this is a rate for, set by the source that quoted it. A property of the service binds every source that sells it (ADR-0006 decision 10), and this is how the shared filters find the service without looking it up by carrier name and code string. Null when the source quoted something the catalog does not hold.
     * @param  int|null  $carrierId  The {@see Carrier} expected to carry the parcel. Stored on the offer, so a purchase is dated by it rather than by a carrier-name string a rename could break. Null only for a carrier with no row.
     * @param  bool  $contentRestricted  Valid only for contents nothing in PolyBag vouches for, so only a person may choose it. Shown on the Ship page, and withheld from automation by {@see RateSelector::selectForAutomation()} whatever the approvals say. Amazon's Bound Printed Matter is the one case (ADR-0006 decision 10).
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
        public ?int $carrierAccountId = null,
        public ?int $carrierServiceId = null,
        public ?int $carrierId = null,
        public bool $contentRestricted = false,
    ) {
        $this->packagingRequirement = $packagingRequirement ?? PackagingRequirement::shipperPackaging();
    }

    /**
     * This rate, now backed by an offer.
     *
     * For the rate service's shared loop, which issues an offer for every
     * direct-carrier rate after the adapters have returned: the rate is
     * otherwise complete and immutable, and the identifier is the one fact
     * that cannot exist until the row does.
     */
    public function withOfferId(string $offerId): self
    {
        return $this->copy(['offerId' => $offerId]);
    }

    /**
     * This rate, naming the catalog service and carrier it is for.
     *
     * For a direct adapter, which reads the ids off its own carrier's catalog
     * after the carrier has answered, and for a shipping rule, which names a
     * service it already holds.
     */
    public function withCatalogIdentity(?int $carrierId, ?int $carrierServiceId): self
    {
        return $this->copy(['carrierId' => $carrierId, 'carrierServiceId' => $carrierServiceId]);
    }

    /**
     * Every field carried across with the given ones replaced, so a new field
     * cannot be forgotten by one of the `with…()` methods above.
     *
     * @param  array<string, mixed>  $changes  Constructor arguments by name
     */
    private function copy(array $changes): self
    {
        return new self(...[
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
            'observedService' => $this->observedService,
            'packagingRequirement' => $this->packagingRequirement,
            'carrierAccountId' => $this->carrierAccountId,
            'carrierServiceId' => $this->carrierServiceId,
            'carrierId' => $this->carrierId,
            'contentRestricted' => $this->contentRestricted,
            ...$changes,
        ]);
    }

    /**
     * Convert to array format for Livewire serialization.
     *
     * @return array{carrier: string, serviceCode: string, serviceName: string, price: float, deliveryCommitment: ?string, deliveryDate: ?string, transitTime: ?string, metadata: array<string, mixed>, priceUnknown: bool, offerId: ?string, observedService: ?array{source: string, environment: string, channelType: string, externalCarrierId: string, externalServiceId: string}, packagingRequirement: array{kind: string, packagings: list<string>}, carrierAccountId: ?int, carrierServiceId: ?int, carrierId: ?int, contentRestricted: bool}
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
            'carrierAccountId' => $this->carrierAccountId,
            'carrierServiceId' => $this->carrierServiceId,
            'carrierId' => $this->carrierId,
            'contentRestricted' => $this->contentRestricted,
        ];
    }

    /**
     * Create a RateResponse from an array (lossless round-trip from toArray).
     *
     * A missing `packagingRequirement` key — an array serialized before the
     * requirement existed — reads as the shipper's own packaging, which is the
     * safe direction: it accepts nothing a carrier supplies.
     *
     * @param  array{carrier: string, serviceCode: string, serviceName: string, price: float, deliveryCommitment: ?string, deliveryDate: ?string, transitTime: ?string, metadata?: array<string, mixed>, priceUnknown?: bool, offerId?: ?string, observedService?: ?array{source: string, environment: string, channelType?: string, externalCarrierId: string, externalServiceId: string}, packagingRequirement?: ?array{kind?: string, packagings?: list<string>}, carrierAccountId?: ?int, carrierServiceId?: ?int, carrierId?: ?int, contentRestricted?: bool}  $data
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
            carrierAccountId: isset($data['carrierAccountId']) ? (int) $data['carrierAccountId'] : null,
            carrierServiceId: isset($data['carrierServiceId']) ? (int) $data['carrierServiceId'] : null,
            carrierId: isset($data['carrierId']) ? (int) $data['carrierId'] : null,
            contentRestricted: (bool) ($data['contentRestricted'] ?? false),
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
        $detail = $this->carrier === Carrier::USPS
            ? ($this->deliveryCommitment ?? '')
            : ($this->transitTime ?? '');

        return '$'.$price.($detail ? " — {$detail}" : '');
    }
}
