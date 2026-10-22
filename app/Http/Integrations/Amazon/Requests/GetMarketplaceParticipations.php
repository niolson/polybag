<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Http\Integrations\Amazon\ReadsSellerData;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetMarketplaceParticipations extends Request implements ReadsSellerData
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/sellers/v1/marketplaceParticipations';
    }
}
