<?php

namespace App\Http\Integrations\USPS\Requests;

use App\Http\Integrations\USPS\Responses\LabelResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class InternationalLabel extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    protected ?string $response = LabelResponse::class;

    /**
     * Never re-sent by the connector. The label endpoint is not idempotent
     * even under `X-Idempotency-Key`, so a retry after a connection failure
     * is a second purchase; whether the first went through is answered by
     * the reprint under the same key instead.
     */
    public ?int $tries = 1;

    public function resolveEndpoint(): string
    {
        return '/international-labels/v3/international-label';
    }
}
