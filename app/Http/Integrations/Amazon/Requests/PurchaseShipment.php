<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\DeclaresSandboxRegion;
use App\Models\ShippingOffer;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Amazon Shipping API v2 `purchaseShipment` — buys one offer from a `getRates`
 * reply.
 *
 * Not `directPurchaseShipment`, which the pre-`01` notes preferred. That
 * operation takes no `rateId`: its request body is addresses, packages and a
 * channel, and Amazon picks the carrier and the service itself. It is therefore
 * a blind purchase in this codebase's terms — it cannot buy the offer a packer
 * looked at, and cannot buy the one the shipping method's allowance cleared. `01`
 * returned three carriers priced independently for one parcel, which is exactly
 * the choice `directPurchase` throws away.
 *
 * The trade is the 10-minute window: `requestToken` expires and the purchase
 * comes back `TOKEN_EXPIRED`. That is what {@see ShippingOffer::$expires_at}
 * tracks, since the quote publishes no expiry of its own.
 */
class PurchaseShipment extends Request implements DeclaresSandboxRegion, HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $idempotencyKey  Amazon recognizes a retry carrying the same key as the same purchase and returns the original shipment rather than buying a second label. The offer's public identifier is used, so a purchase whose reply never arrived can be asked for again under the identity it was spent as.
     */
    public function __construct(
        private readonly array $payload = [],
        private readonly ?string $idempotencyKey = null,
        private readonly string $businessId = 'AmazonShipping_US',
    ) {}

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return array_filter([
            'x-amzn-shipping-business-id' => $this->businessId,
            'x-amzn-IdempotencyKey' => $this->idempotencyKey,
        ], fn (?string $value): bool => $value !== null);
    }

    public function resolveEndpoint(): string
    {
        return '/shipping/v2/shipments';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->payload;
    }

    public function sandboxRegion(): AmazonSpApiRegion
    {
        return AmazonSpApiRegion::NorthAmerica;
    }
}
