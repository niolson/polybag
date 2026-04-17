<?php

namespace App\Http\Integrations\Fedex\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Confirm a FedEx consolidation.
 *
 * Endpoint: POST /ship/v1/consolidations/confirmations
 * Response path for job ID: output.jobId
 */
class ConfirmConsolidation extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/ship/v1/consolidations/confirmations';
    }
}
