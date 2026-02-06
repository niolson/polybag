<?php

namespace App\Http\Integrations\USPS\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class CancelInternationalLabel extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $trackingNumber,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/international-labels/v3/international-label/{$this->trackingNumber}";
    }
}
