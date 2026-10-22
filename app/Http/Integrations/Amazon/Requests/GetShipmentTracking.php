<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\DeclaresSandboxRegion;
use App\Http\Integrations\Amazon\PostageRequest;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Amazon Shipping API v2 `getTracking`.
 *
 * Takes Amazon's own `carrierId` alongside the tracking number — the opaque
 * external identifier (`ONTRAC`, `UPS`) the offer was quoted under, not our
 * `Carrier` row's name. It is stored on the package at purchase for exactly
 * this: a parcel carried by a courier we hold no row for still has to be
 * trackable, and Amazon holds the entitlement we do not.
 */
class GetShipmentTracking extends Request implements DeclaresSandboxRegion, PostageRequest
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $trackingId,
        private readonly string $carrierId,
        private readonly string $businessId = 'AmazonShipping_US',
    ) {}

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['x-amzn-shipping-business-id' => $this->businessId];
    }

    public function resolveEndpoint(): string
    {
        return '/shipping/v2/tracking';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'trackingId' => $this->trackingId,
            'carrierId' => $this->carrierId,
        ];
    }

    public function sandboxRegion(): AmazonSpApiRegion
    {
        return AmazonSpApiRegion::NorthAmerica;
    }
}
