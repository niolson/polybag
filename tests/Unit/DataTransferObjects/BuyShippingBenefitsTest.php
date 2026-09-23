<?php

use App\DataTransferObjects\Shipping\BuyShippingBenefits;

it('reads OTDR protection from a production-shaped benefits block', function (): void {
    $benefits = BuyShippingBenefits::fromRateMetadata(['benefits' => [
        'includedBenefits' => [],
        'excludedBenefits' => [
            ['benefit' => 'CLAIMS_PROTECTED', 'reasonCodes' => ['LATE_DELIVERY_RISK']],
            ['benefit' => 'OTDR_PROTECTED', 'reasonCodes' => ['NON_SSA_ORDER', 'LATE_DELIVERY_RISK', 'NON_AHT_ORDER']],
        ],
    ]]);

    expect($benefits->isOtdrProtected())->toBeFalse()
        ->and($benefits->otdrExclusionReasons())->toBe([
            'Shipping Settings Automation is off',
            'late-delivery risk',
            'Average Handling Time automation is off',
        ]);
});

it('treats OTDR_PROTECTED in the included list as protected', function (): void {
    $benefits = BuyShippingBenefits::fromRateMetadata(['benefits' => [
        'includedBenefits' => ['CLAIMS_PROTECTED', 'OTDR_PROTECTED'],
        'excludedBenefits' => [],
    ]]);

    expect($benefits->isOtdrProtected())->toBeTrue()
        ->and($benefits->otdrExclusionReasons())->toBe([]);
});

it('passes an unknown reason code through as Amazon spelled it', function (): void {
    $benefits = BuyShippingBenefits::fromRateMetadata(['benefits' => [
        'includedBenefits' => [],
        'excludedBenefits' => [['benefit' => 'OTDR_PROTECTED', 'reasonCodes' => ['SOMETHING_NEW']]],
    ]]);

    expect($benefits->otdrExclusionReasons())->toBe(['SOMETHING_NEW']);
});

it('has nothing to say about a rate with no benefits block', function (): void {
    expect(BuyShippingBenefits::fromRateMetadata([]))->toBeNull();
});
