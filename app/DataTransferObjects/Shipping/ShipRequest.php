<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Package;

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
    ) {}

    public static function fromPackageAndRate(Package $package, RateResponse $rate): self
    {
        $customsItems = [];

        // Load package items with relationships if not already loaded
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem']);

        foreach ($package->packageItems as $packageItem) {
            $customsItems[] = CustomsItem::fromPackageItem($packageItem);
        }

        return new self(
            fromAddress: AddressData::fromConfig(),
            toAddress: AddressData::fromShipment($package->shipment),
            packageData: PackageData::fromPackage($package),
            selectedRate: $rate,
            customsItems: $customsItems,
        );
    }
}
