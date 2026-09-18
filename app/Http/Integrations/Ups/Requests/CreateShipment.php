<?php

namespace App\Http\Integrations\Ups\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateShipment extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * Never re-sent by the connector: a ship request that got no answer may
     * have created a shipment UPS will bill, and a retry would create a
     * second. Whether the first went through is answered by Label Recovery
     * under the reference it carried instead.
     */
    public ?int $tries = 1;

    public function resolveEndpoint(): string
    {
        return '/api/shipments/v2409/ship';
    }
}
