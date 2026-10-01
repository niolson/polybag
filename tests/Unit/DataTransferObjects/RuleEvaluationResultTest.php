<?php

use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RuleEvaluationResult;
use App\DataTransferObjects\Shipping\RuleRateScope;
use App\Enums\PostageSourceKind;

it('cannot pre-select a scope alongside a blind purchase', function (): void {
    expect(fn (): RuleEvaluationResult => new RuleEvaluationResult(
        preSelectedBlindPurchaseId: BlindPurchaseOffer::identifier('Shopify', 'auto'),
        preSelectedScope: new RuleRateScope([PostageSourceKind::Amazon], null, strict: true),
    ))->toThrow(InvalidArgumentException::class, 'never both');
});
