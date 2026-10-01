<?php

namespace App\Http\Integrations\Ups\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Street-level address validation with classification (request option 3),
 * which returns the residential/commercial indicator alongside the match.
 */
class ValidateAddress extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/addressvalidation/v2/3';
    }
}
