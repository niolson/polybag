<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Models\Package;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

interface CarrierAdapterInterface
{
    /**
     * Get the carrier name (e.g., 'USPS', 'FedEx', 'UPS').
     */
    public function getCarrierName(): string;

    /**
     * Get shipping rates for the given request (synchronous).
     *
     * @param  array<string>  $serviceCodes  Filter to these service codes only
     * @return Collection<int, RateResponse>
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection;

    /**
     * Prepare a rate API request for async sending.
     *
     * Returns a PreparedRateRequest containing a PendingRequest ready to send,
     * or null if no API call is needed (e.g., mock rates, not configured).
     *
     * @param  array<string>  $serviceCodes
     */
    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest;

    /**
     * Parse a rate API response into rate options.
     *
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection;

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

    /**
     * Check if this carrier supports end-of-day manifests (scan forms).
     */
    public function supportsManifest(): bool;

    /**
     * Resolve a rule-pre-selected rate into a fully-qualified rate with metadata.
     *
     * For carriers like USPS where one service code maps to many rate variants
     * (cubic tiers, single-piece, etc.), this fetches rates and picks the cheapest
     * matching variant. Other carriers return the rate as-is.
     */
    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse;
}
