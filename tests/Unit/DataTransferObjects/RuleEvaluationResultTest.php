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
    ))->toThrow(InvalidArgumentException::class, 'never more than one');
});

it('cannot pre-select a source alongside a blind purchase', function (): void {
    expect(fn (): RuleEvaluationResult => new RuleEvaluationResult(
        preSelectedBlindPurchaseId: BlindPurchaseOffer::identifier('Shopify', 'auto'),
        preSelectedSource: 'amazon',
    ))->toThrow(InvalidArgumentException::class, 'never more than one');
});
