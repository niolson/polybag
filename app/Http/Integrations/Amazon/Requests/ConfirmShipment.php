<?php

namespace App\Http\Integrations\Amazon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ConfirmShipment extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $orderId,
        private readonly array $payload = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/orders/v0/orders/'.rawurlencode($this->orderId).'/shipmentConfirmation';
    }

    protected function defaultBody(): array
    {
        return $this->payload;
    }
}
