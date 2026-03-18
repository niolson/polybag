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
        public ?CarbonImmutable $shipDate = null,
    ) {}

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
            saturdayDelivery: (bool) $shippingMethod?->saturday_delivery,
            shipDate: $shipDate,
        );
    }
}
