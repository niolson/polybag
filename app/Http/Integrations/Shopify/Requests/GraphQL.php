<?php

namespace App\Http\Integrations\Shopify\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class GraphQL extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $graphqlQuery,
        private readonly array $variables = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/';
    }

    protected function defaultBody(): array
    {
        $body = ['query' => $this->graphqlQuery];

        if (! empty($this->variables)) {
            $body['variables'] = $this->variables;
        }

        return $body;
    }
}
