<?php

namespace App\Http\Integrations\USPS\Requests;

use App\Http\Integrations\USPS\Responses\LabelResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Reprint an international label by the `X-Idempotency-Key` its purchase
 * carried. Same contract as {@see LabelReprint} on the other label API.
 */
class InternationalLabelReprint extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    protected ?string $response = LabelResponse::class;

    public ?int $tries = 1;

    /**
     * @param  array<string, mixed>  $imageInfo
     */
    public function __construct(
        protected string $idempotencyKey,
        protected array $imageInfo = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/international-labels/v3/international-label-reprint';
    }

    protected function defaultHeaders(): array
    {
        return ['X-Idempotency-Key' => $this->idempotencyKey];
    }

    protected function defaultBody(): array
    {
        return ['imageInfo' => $this->imageInfo];
    }
}
