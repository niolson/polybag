<?php

namespace App\Http\Integrations\Fedex\Requests\Registration;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class SendPin extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $accountAuthToken,
        private readonly string $option,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/registration/v2/customerkeys/pingeneration';
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
            'option' => $this->option,
        ];
    }
}
