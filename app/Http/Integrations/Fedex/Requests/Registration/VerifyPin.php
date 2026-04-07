<?php

namespace App\Http\Integrations\Fedex\Requests\Registration;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class VerifyPin extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $accountAuthToken,
        private readonly string $pin,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/registration/v2/pin/keysgeneration';
    }

    public function defaultHeaders(): array
    {
        return [
            'accountAuthToken' => $this->accountAuthToken,
        ];
    }

    protected function defaultBody(): array
    {
        return [
            'secureCodePin' => $this->pin,
        ];
    }
}
