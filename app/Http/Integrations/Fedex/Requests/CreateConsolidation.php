<?php

namespace App\Http\Integrations\Fedex\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Create a new FedEx consolidation.
 *
 * Endpoint: POST /ship/v1/consolidations
 * Response path for consolidation key: output.consolidationKey.{type, index, date}
 */
class CreateConsolidation extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/ship/v1/consolidations';
    }
}
