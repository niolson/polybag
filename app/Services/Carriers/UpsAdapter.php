<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Models\Package;
use Illuminate\Support\Collection;

class UpsAdapter implements CarrierAdapterInterface
{
    public function getCarrierName(): string
    {
        return 'UPS';
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        // TODO: Implement UPS rate fetching when UPS integration is added
        logger()->info('UPS getRates called but not yet implemented');

        return collect();
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        // TODO: Implement UPS shipment creation when UPS integration is added
        return ShipResponse::failure('UPS shipping is not yet implemented.');
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        return CancelResponse::failure('UPS cancellation is not yet implemented.');
    }

    public function isConfigured(): bool
    {
        // Return false until UPS credentials are configured
        return ! empty(config('services.ups.client_id'))
            && ! empty(config('services.ups.client_secret'))
            && ! empty(config('services.ups.account_number'));
    }

    public function supportsMultiPackage(): bool
    {
        return true;
    }
}
