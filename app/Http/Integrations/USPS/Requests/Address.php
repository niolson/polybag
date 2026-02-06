<?php

namespace App\Http\Integrations\USPS\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class Address extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/addresses/v3/address';
    }
}
