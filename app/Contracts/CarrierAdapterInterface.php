<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Models\Package;
use Illuminate\Support\Collection;

interface CarrierAdapterInterface
{
    /**
     * Get the carrier name (e.g., 'USPS', 'FedEx', 'UPS').
     */
    public function getCarrierName(): string;

    /**
     * Get shipping rates for the given request.
     *
     * @param  array<string>  $serviceCodes  Filter to these service codes only
     * @return Collection<int, RateResponse>
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection;

    /**
     * Create a shipment and return the result with tracking/label info.
     */
    public function createShipment(ShipRequest $request): ShipResponse;

    /**
     * Check if this carrier adapter is properly configured.
     */
    public function isConfigured(): bool;

    /**
     * Cancel/void a shipment label.
     */
    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse;

    /**
     * Check if this carrier supports multi-package shipments.
     */
    public function supportsMultiPackage(): bool;
}
