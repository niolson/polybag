<?php

namespace App\Http\Integrations\Fedex\Requests\Registration;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ValidateAddress extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $accountNumber,
        private readonly string $customerName,
        private readonly bool $residential,
        private readonly string $street1,
        private readonly string $street2,
        private readonly string $city,
        private readonly string $stateOrProvinceCode,
        private readonly string $postalCode,
        private readonly string $countryCode,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/registration/v2/address/keysgeneration';
    }

    protected function defaultBody(): array
    {
        $streetLines = array_values(array_filter([$this->street1, $this->street2]));

        return [
            'accountNumber' => ['value' => $this->accountNumber],
            'customerName' => $this->customerName,
            'address' => [
                'residential' => $this->residential,
                'streetLines' => $streetLines,
                'city' => $this->city,
                'stateOrProvinceCode' => $this->stateOrProvinceCode,
                'postalCode' => $this->postalCode,
                'countryCode' => $this->countryCode,
            ],
        ];
    }
}
