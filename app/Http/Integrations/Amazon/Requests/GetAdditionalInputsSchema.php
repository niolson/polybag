<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\DeclaresSandboxRegion;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Amazon Shipping API v2 `getAdditionalInputs`.
 *
 * Returns the JSON schema a `purchaseShipment` of one rate must satisfy in its
 * `additionalInputs`, keyed by the same `requestToken` and `rateId` the purchase
 * spends. Only a rate that set `requiresAdditionalInputs: true` has anything to
 * say here; asked about any other rate, production answers 200 with an empty
 * schema. The schema is published nowhere else — Amazon's support confirmed
 * that on 2026-09-16 (`amazon-buy-shipping/13`) — so this call is the only
 * way to learn what a cross-border offer wants, and it costs nothing and
 * touches nothing.
 *
 * The one sandbox case is static and returns `payload: {}`.
 */
class GetAdditionalInputsSchema extends Request implements DeclaresSandboxRegion
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $requestToken,
        private readonly string $rateId,
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
        return '/shipping/v2/shipments/additionalInputs/schema';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'requestToken' => $this->requestToken,
            'rateId' => $this->rateId,
        ];
    }

    public function sandboxRegion(): AmazonSpApiRegion
    {
        return AmazonSpApiRegion::NorthAmerica;
    }
}
