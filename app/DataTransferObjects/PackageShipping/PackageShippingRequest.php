<?php

namespace App\DataTransferObjects\PackageShipping;

use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateResponse;
use InvalidArgumentException;

readonly class PackageShippingRequest
{
    /**
     * @param  RateResponse|null  $selectedRate  The quoted rate to buy
     * @param  BlindPurchaseOffer|null  $blindOffer  A priceless offer to buy instead — exactly one of the two, never both
     */
    public function __construct(
        public ?RateResponse $selectedRate = null,
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public bool $overrideCustomsWeights = false,
        // Whether to pause and prompt the user when customs item weights exceed package weight.
        // Set false for batch/auto-ship flows that have no interactive prompt.
        public bool $requireCustomsWeightOverride = true,
        // Whether the operator has already been shown that the seller declares
        // more weight for the goods than the box weighs, and asked for the
        // purchase to be attempted anyway at the scale weight.
        public bool $overrideDeclaredWeight = false,
        public ?int $userId = null,
        public ?BlindPurchaseOffer $blindOffer = null,
        // Whether the workstation has a report printer to print a separate
        // customs document on. Browser state (`localStorage.reportPrinter`)
        // pushed into Livewire before the purchase, the way label format is;
        // the server cannot see printer configuration any other way. Absent
        // means not configured, and a purchase that returns a separate
        // document is refused before anything is bought.
        public bool $hasReportPrinter = false,
    ) {
        if (($selectedRate === null) === ($blindOffer === null)) {
            throw new InvalidArgumentException('A shipping request buys either a quoted rate or a blind purchase offer, and must name exactly one.');
        }
    }
}
