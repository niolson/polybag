<?php

use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\RuleEvaluationResult;

it('cannot pre-select a rate and a blind purchase together', function (): void {
    $rate = new RateResponse(
        carrier: 'USPS',
        serviceCode: 'USPS_GROUND_ADVANTAGE',
        serviceName: 'Ground Advantage',
        price: 8.50,
        packagingRequirement: PackagingRequirement::shipperPackaging(),
    );

    expect(fn (): RuleEvaluationResult => new RuleEvaluationResult(
        preSelectedRate: $rate,
        preSelectedBlindPurchaseId: BlindPurchaseOffer::identifier('Shopify', 'auto'),
    ))->toThrow(InvalidArgumentException::class, 'either a rate or a blind purchase');
});
