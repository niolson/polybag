<?php

namespace App\Http\Integrations\Fedex\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Retrieve results for a confirmed FedEx consolidation.
 *
 * Endpoint: POST /ship/v1/consolidations/results
 * The jobId returned from ConfirmConsolidation is sent in the request body.
 */
class GetConsolidationResults extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/ship/v1/consolidations/results';
    }
}
