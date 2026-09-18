<?php

namespace App\Http\Integrations\USPS\Requests;

use App\Http\Integrations\USPS\Responses\LabelResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Reprint a domestic label by the `X-Idempotency-Key` its purchase carried.
 *
 * The question behind `RecoversUnresolvedPurchase` for USPS: a 200 is the
 * original label and its metadata, so the same multipart parser applies;
 * error `160412` is USPS saying no label was ever bought under that key.
 * Sent once — USPS allows three reprints per label, and a retry would spend
 * them on the same question.
 */
class LabelReprint extends Request implements HasBody
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
        return '/labels/v3/label-reprint';
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
