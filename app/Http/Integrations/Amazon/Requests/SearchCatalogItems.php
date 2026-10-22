<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Http\Integrations\Amazon\ReadsSellerData;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class SearchCatalogItems extends Request implements ReadsSellerData
{
    protected Method $method = Method::GET;

    /** AmazonSource owns 429 pacing and backoff so it can honor SP-API rate-limit headers. */
    public ?int $tries = 1;

    public function __construct(
        private readonly array $queryParams = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/catalog/2022-04-01/items';
    }

    protected function defaultQuery(): array
    {
        return $this->queryParams;
    }
}
