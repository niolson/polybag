<?php

namespace App\Http\Integrations\Fedex\Requests;

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
        return '/track/v1/trackingnumbers';
    }

    protected function defaultBody(): array
    {
        return [
            'includeDetailedScans' => true,
            'trackingInfo' => [
                [
                    'trackingNumberInfo' => [
                        'trackingNumber' => $this->trackingNumber,
                    ],
                ],
            ],
        ];
    }
}
