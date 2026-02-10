<?php

namespace App\Http\Integrations\Ups\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class VoidShipment extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $shipmentIdentificationNumber,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/shipments/v2409/void/cancel/'.$this->shipmentIdentificationNumber;
    }
}
