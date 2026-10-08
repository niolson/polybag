<?php

namespace App\DataTransferObjects\PackageShipping;

readonly class PackageShippingOptions
{
    /**
     * @param  array<int, array<string, mixed>>  $rateOptions
     * @param  array<int, string>  $rateOptionLabels
     * @param  array<int, string>  $rateOptionDescriptions
     * @param  array<int, array{carrier: string, reason: string}>  $exclusions
     * @param  array<int, array<string, mixed>>  $blindPurchaseOffers  Priceless offers, kept out of `$rateOptions` so nothing can rank them against a quote (ADR-0003 decision 6)
     * @param  list<array{carrier: string|null, reason: string, fixUrl: string|null, fixLabel: string|null}>  $droppedRates  Why quoted rates were dropped for the Shipment's customs terms, one per carrier and reason (ADR-0008 decision 4)
     * @param  bool  $allRatesDroppedForCustomsTerms  Rates were quoted and the customs terms dropped every one, rather than no source answering
     */
    public function __construct(
        public array $rateOptions,
        public array $rateOptionLabels,
        public array $rateOptionDescriptions,
        public ?string $deliverByDate,
        public bool $allRatesLate,
        public array $exclusions = [],
        public ?int $selectedRateIndex = null,
        public ?string $blockingError = null,
        public array $blindPurchaseOffers = [],
        public array $droppedRates = [],
        public bool $allRatesDroppedForCustomsTerms = false,
    ) {}

    public static function blocked(string $message): self
    {
        return new self(
            rateOptions: [],
            rateOptionLabels: [],
            rateOptionDescriptions: [],
            deliverByDate: null,
            allRatesLate: false,
            blockingError: $message,
        );
    }
}
