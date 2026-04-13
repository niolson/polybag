<?php

namespace App\Http\Integrations\USPS\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class TrackShipment extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $trackingNumber,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/tracking/v3r2/tracking';
    }

    protected function defaultBody(): array
    {
        return [
            [
                'trackingNumber' => $this->trackingNumber,
            ],
        ];
    }
}
