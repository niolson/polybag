<?php

namespace App\Services;

use App\Events\PackageCreated;
use App\Models\Package;
use App\Models\Shipment;

class PackagingService
{
    /**
     * Create a shipment from manually-entered data.
     * Does not validate the address — callers opt in to that separately.
     */
    public function createShipment(array $data, ?int $channelId = null): Shipment
    {
        return Shipment::create([
            'shipment_reference' => $data['shipment_reference'] ?: null,
            'first_name' => $data['first_name'] ?: null,
            'last_name' => $data['last_name'] ?: null,
            'company' => $data['company'] ?: null,
            'address1' => $data['address1'],
            'address2' => $data['address2'] ?: null,
            'city' => $data['city'],
            'state_or_province' => $data['state_or_province'] ?: null,
            'postal_code' => $data['postal_code'] ?: null,
            'country' => $data['country'],
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'shipping_method_id' => $data['shipping_method_id'] ?: null,
            'channel_id' => $channelId,
            'status' => 'open',
        ]);
    }

    /**
     * Create a package for a shipment, attach items, compute weight mismatch, and dispatch PackageCreated.
     * Does not manage transactions — the caller decides the transaction boundary.
     *
     * @param  array<int, array{shipment_item_id: int, product_id: int, quantity: int, transparency_codes: array<string>}>  $packingItems
     */
    public function createPackage(
        Shipment $shipment,
        string|float $weight,
        string|float $height,
        string|float $width,
        string|float $length,
        ?int $boxSizeId = null,
        array $packingItems = [],
    ): Package {
        $package = Package::create([
            'shipment_id' => $shipment->id,
            'box_size_id' => $boxSizeId,
            'weight' => $weight,
            'height' => $height,
            'width' => $width,
            'length' => $length,
        ]);

        if (! empty($packingItems)) {
            $package->packageItems()->createMany($packingItems);
        }

        $package->update(['weight_mismatch' => $package->computeWeightMismatch()]);

        PackageCreated::dispatch($package, $shipment);

        return $package;
    }
}
