<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\DeclaresSandboxRegion;
use App\Http\Integrations\Amazon\ReadsSellerData;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;

class SearchOrders extends Request implements DeclaresSandboxRegion, ReadsSellerData
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

    /**
     * Not retried when throttled. `searchOrders` refills one request every
     * three minutes, so a retry moments later is refused too; the import keeps
     * what it read and carries on next run.
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        return ! ($exception instanceof RequestException && $exception->getResponse()->status() === 429);
    }

    /**
     * The only Orders v2026-01-01 sandbox test case we can drive uses Amazon's JP
     * marketplace, which resolves against the FE host alone.
     */
    public function sandboxRegion(): AmazonSpApiRegion
    {
        return AmazonSpApiRegion::FarEast;
    }
}
