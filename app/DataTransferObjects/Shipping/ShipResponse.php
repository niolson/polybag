<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use Carbon\CarbonImmutable;

readonly class ShipResponse
{
    /**
     * @param  array<string>  $appliedServices  Carrier-agnostic service codes actually sent to the carrier (e.g. 'saturday_delivery')
     * @param  string|null  $customsFormData  Base64 of a customs document returned separately from the label, for the report printer
     * @param  array<string, mixed>  $metadata  Carrier-specific facts worth keeping on the package (e.g. what Shopify chose)
     * @param  PostageSource  $postageSource  Where the postage was bought. Defaults to the direct-carrier case; sales-channel postage must say so.
     * @param  int|null  $postageDataSourceId  The data source the postage was bought through, required when $postageSource is PostageDataSource
     * @param  string|null  $requestedService  What the source was asked for. Audit metadata, never the service value — a source may ignore it.
     * @param  ServiceEvidence  $serviceEvidence  How well $service is known. Defaults to the direct-carrier case, which reports what it sold.
     * @param  string|null  $serviceInferenceMethod  How $service was derived, required when $serviceEvidence is Inferred
     * @param  string|null  $serviceRulesetVersion  Which ruleset derived it, required when $serviceEvidence is Inferred
     */
    public function __construct(
        public bool $success,
        public ?string $trackingNumber = null,
        public ?float $cost = null,
        public ?string $carrier = null,
        public ?string $service = null,
        public ?string $labelData = null,
        public ?string $labelOrientation = null,
        public ?string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public ?string $customsFormData = null,
        public ?CarbonImmutable $shipDate = null,
        public ?string $errorMessage = null,
        public array $appliedServices = [],
        public ?int $carrierAccountId = null,
        public array $metadata = [],
        public PostageSource $postageSource = PostageSource::CarrierAccount,
        public ?int $postageDataSourceId = null,
        public ?string $requestedService = null,
        public ServiceEvidence $serviceEvidence = ServiceEvidence::Confirmed,
        public ?string $serviceInferenceMethod = null,
        public ?string $serviceRulesetVersion = null,
    ) {}

    /**
     * @param  array<string>  $appliedServices
     */
    public static function success(
        string $trackingNumber,
        float $cost,
        string $carrier,
        string $service,
        ?string $labelData = null,
        string $labelOrientation = 'portrait',
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
        ?CarbonImmutable $shipDate = null,
        array $appliedServices = [],
        ?int $carrierAccountId = null,
    ): self {
        return new self(
            success: true,
            trackingNumber: $trackingNumber,
            cost: $cost,
            carrier: $carrier,
            service: $service,
            labelData: $labelData,
            labelOrientation: $labelOrientation,
            labelFormat: $labelFormat,
            labelDpi: $labelDpi,
            shipDate: $shipDate,
            appliedServices: $appliedServices,
            carrierAccountId: $carrierAccountId,
        );
    }

    public static function failure(string $errorMessage): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
        );
    }
}
