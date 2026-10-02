<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\DeclaresSandboxRegion;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Amazon Shipping API v2 `getShipmentDocuments`: the documents of a shipment
 * already bought, by the shipment ID `purchaseShipment` returned.
 *
 * A reprint, not a way to another format. For an Amazon order's own postage
 * Amazon refuses `format` and `dpi` ("same options selected in Purchase call
 * will be applied", `amazon-buy-shipping/03`), so they are sent only for
 * Amazon Shipping on an order from another channel, where the sandbox took
 * `format` (`amazon-shipping-external-orders/01`).
 */
class GetShipmentDocuments extends Request implements DeclaresSandboxRegion
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $shipmentId,
        private readonly string $packageClientReferenceId,
        private readonly ?string $format = null,
        private readonly ?int $dpi = null,
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
        return '/shipping/v2/shipments/'.rawurlencode($this->shipmentId).'/documents';
    }

    /**
     * @return array<string, string|int>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'packageClientReferenceId' => $this->packageClientReferenceId,
            'format' => $this->format,
            'dpi' => $this->dpi,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function sandboxRegion(): AmazonSpApiRegion
    {
        return AmazonSpApiRegion::NorthAmerica;
    }
}
