<?php

namespace App\Http\Integrations\Amazon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class SearchOrders extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly array $queryParams = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/orders/2026-01-01/orders';
    }

    protected function defaultQuery(): array
    {
        return $this->queryParams;
    }
}
