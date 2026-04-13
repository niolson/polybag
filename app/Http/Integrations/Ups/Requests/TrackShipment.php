<?php

namespace App\Http\Integrations\Ups\Requests;

use Illuminate\Support\Str;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class TrackShipment extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $inquiryNumber,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/track/v1/details/{$this->inquiryNumber}";
    }

    protected function defaultHeaders(): array
    {
        return [
            'transId' => (string) Str::uuid(),
            'transactionSrc' => 'polybag',
        ];
    }

    protected function defaultQuery(): array
    {
        return [
            'locale' => 'en_US',
            'returnSignature' => 'false',
            'returnMilestones' => 'false',
            'returnPOD' => 'false',
        ];
    }
}
