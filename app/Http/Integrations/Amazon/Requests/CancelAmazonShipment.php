<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\DeclaresSandboxRegion;
use App\Http\Integrations\Amazon\PostageRequest;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Amazon Shipping API v2 `cancelShipment`.
 *
 * Named apart from {@see ConfirmShipment}, which is the Orders API's
 * write-back, because "cancel a shipment" and "confirm a shipment" are two
 * different APIs here and only one of them voids a label.
 *
 * Takes the `shipmentId` `purchaseShipment` returned — not a tracking number
 * and not a rate. A successful cancel returns 200 with an empty payload.
 */
class CancelAmazonShipment extends Request implements DeclaresSandboxRegion, PostageRequest
{
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $shipmentId,
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
        return '/shipping/v2/shipments/'.rawurlencode($this->shipmentId).'/cancel';
    }

    public function sandboxRegion(): AmazonSpApiRegion
    {
        return AmazonSpApiRegion::NorthAmerica;
    }
}
