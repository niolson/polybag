<?php

namespace App\Http\Integrations\USPS\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class CancelLabel extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $trackingNumber,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/labels/v3/label/{$this->trackingNumber}";
    }
}
