<?php

namespace App\DataTransferObjects\PackageShipping;

readonly class PackageShippingOptions
{
    /**
     * @param  array<int, array<string, mixed>>  $rateOptions
     * @param  array<int, string>  $rateOptionLabels
     * @param  array<int, string>  $rateOptionDescriptions
     * @param  array<int, array{carrier: string, reason: string}>  $exclusions
     */
    public function __construct(
        public array $rateOptions,
        public array $rateOptionLabels,
        public array $rateOptionDescriptions,
        public ?string $deliverByDate,
        public bool $allRatesLate,
        public array $exclusions = [],
        public ?int $selectedRateIndex = null,
    ) {}
}
