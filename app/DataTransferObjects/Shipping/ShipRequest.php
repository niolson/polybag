<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Package;
use App\Services\ShipDateService;
use Carbon\CarbonImmutable;

readonly class ShipRequest
{
    /**
     * @param  array<int, CustomsItem>  $customsItems
     */
    public function __construct(
        public AddressData $fromAddress,
        public AddressData $toAddress,
        public PackageData $packageData,
        public RateResponse $selectedRate,
        public array $customsItems = [],
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public bool $saturdayDelivery = false,
        public ?int $locationId = null,
        public ?CarbonImmutable $shipDate = null,
    ) {}

    /**
     * Scale customs item weights proportionally so their total matches the package weight.
     */
    public function withScaledCustomsWeights(): self
    {
        if (empty($this->customsItems)) {
            return $this;
        }

        $totalCustomsWeight = collect($this->customsItems)->sum(fn ($item) => $item->weight * $item->quantity);
        $packageWeight = $this->packageData->weight;

        if ($totalCustomsWeight <= $packageWeight || $totalCustomsWeight == 0) {
            return $this;
        }

        $scale = $packageWeight / $totalCustomsWeight;

        $scaledItems = array_map(
            fn (CustomsItem $item) => new CustomsItem(
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
            saturdayDelivery: $this->saturdayDelivery,
            locationId: $this->locationId,
            shipDate: $this->shipDate,
        );
    }

    public static function fromPackageAndRate(
        Package $package,
        RateResponse $rate,
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
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

        $shippingMethod = $package->shipment->shippingMethod;

        $shipDate = app(ShipDateService::class)->getShipDate($rate->carrier, $package->location_id);

        return new self(
            fromAddress: $fromAddress,
            toAddress: AddressData::fromShipment($package->shipment),
            packageData: PackageData::fromPackage($package),
            selectedRate: $rate,
            customsItems: $customsItems,
            labelFormat: $labelFormat,
            labelDpi: $labelDpi,
            saturdayDelivery: (bool) $shippingMethod?->hasDefaultService('saturday_delivery'),
            locationId: $package->location_id,
            shipDate: $shipDate,
        );
    }
}
