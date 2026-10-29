<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Http\Integrations\Amazon\ReadsSellerData;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Listings Items `2021-08-01` `searchListingsItems`: a page of the seller's
 * own listings (`product-catalog-sync/04`).
 *
 * @see https://developer-docs.amazon.com/sp-api/docs/listings-items-api-v2021-08-01-reference
 */
class SearchListingsItems extends Request implements ReadsSellerData
{
    /** Requests per second when Amazon's answer does not say (`5.0` in `product-catalog-sync/03`). */
    public const DEFAULT_RATE_LIMIT = 5.0;

    protected Method $method = Method::GET;

    /** AmazonRequestPacer owns 429 pacing and backoff so it can honor SP-API rate-limit headers. */
    public ?int $tries = 1;

    /**
     * @param  array<string, mixed>  $queryParams
     */
    public function __construct(
        private readonly string $sellerId,
        private readonly array $queryParams = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/listings/2021-08-01/items/'.rawurlencode($this->sellerId);
    }

    protected function defaultQuery(): array
    {
        return $this->queryParams;
    }
}
