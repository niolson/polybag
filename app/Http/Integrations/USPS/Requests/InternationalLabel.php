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

    public function resolveEndpoint(): string
    {
        return '/international-labels/v3/international-label';
    }
}
