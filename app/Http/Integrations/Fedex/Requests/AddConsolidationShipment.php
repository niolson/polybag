<?php

namespace App\Http\Integrations\Fedex\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Add a shipment to an existing FedEx consolidation.
 *
 * Endpoint: POST /ship/v1/consolidations/shipments
 * The consolidation key (type, index, date) is sent in the request body.
 */
class AddConsolidationShipment extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/ship/v1/consolidations/shipments';
    }
}
