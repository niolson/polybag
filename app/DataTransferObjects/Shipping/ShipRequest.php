<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\ServiceCapability;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\LabelReferenceResolver;
use App\Services\ShipDateService;
use App\Services\SpecialServiceResolver;
use Carbon\CarbonImmutable;

readonly class ShipRequest
{
    /**
     * @param  RateResponse|null  $selectedRate  What was quoted and chosen — null for a blind purchase, which had no price or service to quote
     * @param  BlindPurchaseOffer|null  $blindOffer  The priceless offer being bought instead, when there is one. Exactly one of the two is set.
     * @param  array<int, CustomsItem>  $customsItems
     * @param  array<int, string>  $specialServiceCodes
     * @param  array<string, array<string, mixed>>  $specialServiceConfig  Per-code config values (e.g. declared_value amount)
     * @param  array<int, string>  $references  Identifiers to print on the label, longest-lived first; carriers truncate to their own limits
     * @param  ShippingOffer|null  $offer  The purchase authority behind $selectedRate, when the source issued one. Server-side only and never serialized: it holds the opaque tokens that actually buy the label, which is why an adapter reads them from here rather than from the rate. ADR-0002 decision 4.
     * @param  bool  $overrideDeclaredWeight  The operator has been shown that the seller declares more weight for the goods than the box was weighed at, and has asked for the purchase to be attempted anyway — at the scale weight, unchanged. Nothing is over-declared by it; it exists so a catalogue corrected between the refusal and the retry, or a reading of ours that was wrong, is not a dead end.
     */
    public function __construct(
        public AddressData $fromAddress,
        public AddressData $toAddress,
        public PackageData $packageData,
        public ?RateResponse $selectedRate = null,
        public array $customsItems = [],
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public array $specialServiceCodes = [],
        public ?int $locationId = null,
        public ?int $clientId = null,
        public ?CarbonImmutable $shipDate = null,
        public array $specialServiceConfig = [],
        public array $references = [],
        public ?int $packageId = null,
        public ?BlindPurchaseOffer $blindOffer = null,
        public ?ShippingOffer $offer = null,
        public bool $overrideDeclaredWeight = false,
    ) {}

    public function hasSpecialService(string $code): bool
    {
        return in_array($code, $this->specialServiceCodes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function specialServiceConfig(string $code): array
    {
        return $this->specialServiceConfig[$code] ?? [];
    }

    public function withoutSpecialService(string $code): self
    {
        return $this->withSpecialServiceCodes(
            array_values(array_diff($this->specialServiceCodes, [$code])),
        );
    }

    /**
     * @param  array<int, string>  $codes
     */
    public function withSpecialServiceCodes(array $codes): self
    {
        return new self(
            fromAddress: $this->fromAddress,
            toAddress: $this->toAddress,
            packageData: $this->packageData,
            selectedRate: $this->selectedRate,
            customsItems: $this->customsItems,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            specialServiceCodes: $codes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            references: $this->references,
            packageId: $this->packageId,
            blindOffer: $this->blindOffer,
            offer: $this->offer,
            overrideDeclaredWeight: $this->overrideDeclaredWeight,
        );
    }

    /**
     * Scale customs item weights proportionally so their total matches the package weight.
     */
    public function withScaledCustomsWeights(): self
    {
        if (empty($this->customsItems)) {
            return $this;
        }

        $totalCustomsWeight = collect($this->customsItems)->sum(fn ($item): float => $item->weight * $item->quantity);
        $packageWeight = $this->packageData->weight;

        if ($totalCustomsWeight <= $packageWeight || $totalCustomsWeight == 0) {
            return $this;
        }

        $scale = $packageWeight / $totalCustomsWeight;

        $scaledItems = array_map(
            fn (CustomsItem $item): CustomsItem => new CustomsItem(
                description: $item->description,
                quantity: $item->quantity,
                unitValue: $item->unitValue,
                weight: round($item->weight * $scale, 2),
                hsTariffNumber: $item->hsTariffNumber,
                countryOfOrigin: $item->countryOfOrigin,
            ),
            $this->customsItems,
        );

        return new self(
            fromAddress: $this->fromAddress,
            toAddress: $this->toAddress,
            packageData: $this->packageData,
            selectedRate: $this->selectedRate,
            customsItems: $scaledItems,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            specialServiceCodes: $this->specialServiceCodes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            references: $this->references,
            packageId: $this->packageId,
            blindOffer: $this->blindOffer,
            offer: $this->offer,
            overrideDeclaredWeight: $this->overrideDeclaredWeight,
        );
    }

    /**
     * Carry the operator's decision to attempt a purchase the seller's own
     * declared weight would have withheld.
     *
     * A flag rather than a changed weight: the scale reading is what gets sent
     * either way. Only the refusal is waived.
     */
    public function withDeclaredWeightOverride(): self
    {
        return new self(
            fromAddress: $this->fromAddress,
            toAddress: $this->toAddress,
            packageData: $this->packageData,
            selectedRate: $this->selectedRate,
            customsItems: $this->customsItems,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            specialServiceCodes: $this->specialServiceCodes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            references: $this->references,
            packageId: $this->packageId,
            blindOffer: $this->blindOffer,
            offer: $this->offer,
            overrideDeclaredWeight: true,
        );
    }

    public static function fromPackageAndRate(
        Package $package,
        RateResponse $rate,
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
        ?ShippingOffer $offer = null,
    ): self {
        $customsItems = [];

        // Load package items with relationships if not already loaded
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem']);

        foreach ($package->packageItems as $packageItem) {
            $customsItems[] = CustomsItem::fromPackageItem($packageItem);
        }

        $package->loadMissing('location');
        $fromAddress = $package->location
            ? AddressData::fromLocation($package->location)
            : AddressData::fromConfig();

        $shipDate = app(ShipDateService::class)->getShipDate($rate->carrier, $package->location_id);

        $resolver = app(SpecialServiceResolver::class);
        $specialServiceCodes = $resolver->resolveForPackageAndRate($package, $rate);

        return new self(
            fromAddress: $fromAddress,
            toAddress: AddressData::fromShipment($package->shipment),
            packageData: PackageData::fromPackage($package),
            selectedRate: $rate,
            customsItems: $customsItems,
            labelFormat: $labelFormat,
            labelDpi: $labelDpi,
            specialServiceCodes: $specialServiceCodes,
            locationId: $package->location_id,
            clientId: $package->shipment->client_id,
            shipDate: $shipDate,
            specialServiceConfig: $resolver->configForPackage($package, $specialServiceCodes),
            references: app(LabelReferenceResolver::class)->forPackage($package),
            packageId: $package->id,
            offer: $offer,
        );
    }

    /**
     * The same request for a purchase with no rate behind it.
     *
     * Everything a label needs that does not come from a quote is assembled
     * exactly as above — addresses, customs items, references, ship date. What
     * is missing is missing because the source never stated it: no carrier, no
     * service, no price (ADR-0003 decision 6).
     *
     * No special services are sent. A hard-required one excluded this source at
     * offer time, and a default one is dropped rather than requested, because
     * an unconstrained selection cannot promise to apply it — see
     * {@see ServiceCapability::Unguaranteed}.
     *
     * The ship date is still resolved through `ShipDateService` under the
     * source's name, which is where Shopify's own pickup and cutoff policy is
     * configured.
     */
    public static function fromPackageAndBlindOffer(
        Package $package,
        BlindPurchaseOffer $offer,
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
    ): self {
        $customsItems = [];

        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem']);

        foreach ($package->packageItems as $packageItem) {
            $customsItems[] = CustomsItem::fromPackageItem($packageItem);
        }

        $package->loadMissing('location');
        $fromAddress = $package->location
            ? AddressData::fromLocation($package->location)
            : AddressData::fromConfig();

        return new self(
            fromAddress: $fromAddress,
            toAddress: AddressData::fromShipment($package->shipment),
            packageData: PackageData::fromPackage($package),
            customsItems: $customsItems,
            labelFormat: $labelFormat,
            labelDpi: $labelDpi,
            locationId: $package->location_id,
            clientId: $package->shipment->client_id,
            shipDate: app(ShipDateService::class)->getShipDate($offer->source, $package->location_id),
            references: app(LabelReferenceResolver::class)->forPackage($package),
            packageId: $package->id,
            blindOffer: $offer,
        );
    }
}
