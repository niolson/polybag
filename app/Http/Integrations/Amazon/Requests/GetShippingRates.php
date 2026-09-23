<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\DeclaresSandboxRegion;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Amazon Shipping API v2 `getRates`.
 *
 * Returns a `requestToken` plus one `Rate` per eligible offer, each carrying its own
 * `rateId`, carrier and service identity, price and promise. Both the token and the
 * rate ID are required by `purchaseShipment` and cannot be reconstructed from the
 * carrier and service, so they are the offer's identity — see ADR-0002 decision 4.
 *
 * `ineligibleRates` names services that exist but did not apply, with reason codes.
 */
class GetShippingRates extends Request implements DeclaresSandboxRegion, HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload = [],
        private readonly string $businessId = 'AmazonShipping_US',
    ) {}

    /**
     * Amazon defaults this header to `AmazonShipping_UK` when it is omitted, which is
     * wrong for every marketplace we serve. It selects the regional Amazon shipping
     * business, not the carrier set — Amazon's own on-Amazon example sends
     * `AmazonShipping_US` and still returns USPS alongside Amazon Shipping.
     *
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['x-amzn-shipping-business-id' => $this->businessId];
    }

    /**
     * The Shipping v2 channel this request rates on — `AMAZON` or `EXTERNAL`.
     */
    public function channelType(): ?string
    {
        $channelType = $this->payload['channelDetails']['channelType'] ?? null;

        return is_string($channelType) ? $channelType : null;
    }

    public function resolveEndpoint(): string
    {
        return '/shipping/v2/shipments/rates';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->payload;
    }

    /**
     * Retry what might answer differently a moment later — a dropped
     * connection, throttling, a server error — and never a refusal. Amazon
     * refuses an invalid body or an account not set up for Amazon Shipping
     * (`403 A-101`) the same way every time, and asking again only keeps a
     * packer waiting for the same answer.
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        if (! $exception instanceof RequestException) {
            return true;
        }

        $status = $exception->getResponse()->status();

        return $status >= 500 || $status === 429;
    }

    /**
     * Shipping v2's sandbox test cases are NA. Declared rather than left to the
     * connector default because this API is the reason the default cannot be FE.
     */
    public function sandboxRegion(): AmazonSpApiRegion
    {
        return AmazonSpApiRegion::NorthAmerica;
    }
}
