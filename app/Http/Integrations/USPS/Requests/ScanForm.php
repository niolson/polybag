<?php

namespace App\Http\Integrations\USPS\Requests;

use App\Http\Integrations\USPS\Responses\ScanFormResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ScanForm extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    protected ?string $response = ScanFormResponse::class;

    public function resolveEndpoint(): string
    {
        return '/scan-forms/v3/scan-form';
    }
}
