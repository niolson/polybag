<?php

namespace App\Http\Integrations\Fedex\Requests\Registration;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class VerifyInvoice extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $accountAuthToken,
        private readonly int $invoiceNumber,
        private readonly string $invoiceDate,
        private readonly float $invoiceAmount,
        private readonly string $invoiceCurrency,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/registration/v2/invoice/keysgeneration';
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
            'invoiceDetail' => [
                'number' => $this->invoiceNumber,
                'date' => $this->invoiceDate,
                'amount' => number_format($this->invoiceAmount, 2, '.', ''),
                'currency' => $this->invoiceCurrency,
            ],
        ];
    }
}
