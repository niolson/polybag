<?php

namespace App\Http\Integrations\Ups\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Look a shipment up by one of the reference numbers it was created with.
 *
 * The question behind `RecoversUnresolvedPurchase` for UPS: a 200 carries
 * the tracking number and the label image; `9801031` is UPS not finding
 * anything under the reference and shipper number, and `9801040` finding a
 * shipment that has since been voided. Proven in production on 2026-09-18
 * (`postage-source-split/18`).
 */
class LabelRecovery extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public ?int $tries = 1;

    public function __construct(
        protected string $referenceValue,
        protected string $shipperNumber,
        protected string $labelImageFormat = 'GIF',
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/labels/v1/recovery';
    }

    protected function defaultBody(): array
    {
        return [
            'LabelRecoveryRequest' => [
                'Request' => [
                    'SubVersion' => '1903',
                    'TransactionReference' => [
                        'CustomerContext' => 'Recovery',
                    ],
                ],
                'LabelSpecification' => [
                    'LabelImageFormat' => [
                        'Code' => $this->labelImageFormat,
                    ],
                    // Only a thermal format may name a stock size — with GIF
                    // it is refused (9801050).
                    ...($this->labelImageFormat === 'ZPL' ? [
                        'LabelStockSize' => [
                            'Height' => '6',
                            'Width' => '4',
                        ],
                    ] : []),
                ],
                'ReferenceValues' => [
                    'ReferenceNumber' => [
                        'Value' => $this->referenceValue,
                    ],
                    'ShipperNumber' => $this->shipperNumber,
                ],
            ],
        ];
    }
}
