<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\Enums\TaxRegistrationRegime;

function regimeDestination(string $country): AddressData
{
    return new AddressData(
        firstName: 'Test',
        lastName: 'Recipient',
        streetAddress: '1 Example Street',
        city: 'Example City',
        stateOrProvince: null,
        postalCode: '00000',
        country: $country,
    );
}

it('accepts a well-formed registration number', function (TaxRegistrationRegime $regime, string $number): void {
    expect($regime->isValidNumber($number))->toBeTrue()
        ->and($regime->numberError($number))->toBeNull();
})->with([
    'IOSS' => [TaxRegistrationRegime::Ioss, 'IM0000000001'],
    'IOSS in lower case with spaces' => [TaxRegistrationRegime::Ioss, 'im 000 000 0001'],
    'UK VAT, 9 digits' => [TaxRegistrationRegime::UkVat, 'GB000000001'],
    'UK VAT, 12 digits' => [TaxRegistrationRegime::UkVat, 'GB000000000001'],
    'VOEC' => [TaxRegistrationRegime::Voec, '0000001'],
    'ARN' => [TaxRegistrationRegime::Arn, '000000000001'],
]);

it('rejects a malformed registration number', function (TaxRegistrationRegime $regime, ?string $number): void {
    expect($regime->isValidNumber($number))->toBeFalse()
        ->and($regime->numberError($number))->toContain($regime->numberFormat());
})->with([
    'IOSS without its prefix' => [TaxRegistrationRegime::Ioss, '0000000001'],
    'IOSS with 9 digits' => [TaxRegistrationRegime::Ioss, 'IM000000001'],
    'IOSS with a UK prefix' => [TaxRegistrationRegime::Ioss, 'GB0000000001'],
    'UK VAT with 10 digits' => [TaxRegistrationRegime::UkVat, 'GB0000000001'],
    'UK VAT without its prefix' => [TaxRegistrationRegime::UkVat, '000000001'],
    'VOEC with 8 digits' => [TaxRegistrationRegime::Voec, '00000001'],
    'VOEC with a prefix' => [TaxRegistrationRegime::Voec, 'NO0000001'],
    'ARN with 11 digits' => [TaxRegistrationRegime::Arn, '00000000001'],
    'missing' => [TaxRegistrationRegime::Arn, null],
]);

it('stores a number in upper case without spaces', function (): void {
    expect(TaxRegistrationRegime::Ioss->normalizeNumber(' im 0000 000 001 '))->toBe('IM0000000001');
});

it('covers only its own destinations', function (TaxRegistrationRegime $regime, array $covered, array $notCovered): void {
    foreach ($covered as $country) {
        expect($regime->covers(regimeDestination($country)))->toBeTrue("{$regime->value} should cover {$country}");
    }

    foreach ($notCovered as $country) {
        expect($regime->covers(regimeDestination($country)))->toBeFalse("{$regime->value} should not cover {$country}");
    }
})->with([
    'IOSS covers the EU' => [TaxRegistrationRegime::Ioss, ['DE', 'FR', 'PL', 'IE', 'cy'], ['GB', 'NO', 'CH', 'AU', 'US']],
    'UK VAT covers GB, Northern Ireland included' => [TaxRegistrationRegime::UkVat, ['GB', 'gb'], ['IE', 'DE', 'NO', 'AU']],
    'VOEC covers Norway' => [TaxRegistrationRegime::Voec, ['NO'], ['SE', 'DK', 'GB']],
    'ARN covers Australia' => [TaxRegistrationRegime::Arn, ['AU'], ['NZ', 'GB']],
]);

it('knows its low-value threshold and currency', function (TaxRegistrationRegime $regime, int $threshold, string $currency): void {
    expect($regime->lowValueThreshold())->toBe($threshold)
        ->and($regime->thresholdCurrency())->toBe($currency);
})->with([
    [TaxRegistrationRegime::Ioss, 150, 'EUR'],
    [TaxRegistrationRegime::UkVat, 135, 'GBP'],
    [TaxRegistrationRegime::Voec, 3000, 'NOK'],
    [TaxRegistrationRegime::Arn, 1000, 'AUD'],
]);

it('covers Northern Ireland with IOSS too, at £135', function (): void {
    $belfast = new AddressData('Test', 'Recipient', '1 Example Street', 'Belfast', null, 'BT1 5GS', 'GB');
    $london = new AddressData('Test', 'Recipient', '1 Example Street', 'London', null, 'SW1A 1AA', 'GB');

    expect($belfast->isNorthernIreland())->toBeTrue()
        ->and($london->isNorthernIreland())->toBeFalse()
        ->and(TaxRegistrationRegime::Ioss->covers($belfast))->toBeTrue()
        ->and(TaxRegistrationRegime::Ioss->covers($london))->toBeFalse()
        ->and(TaxRegistrationRegime::Ioss->lowValueThresholdFor($belfast))->toBe(135)
        ->and(TaxRegistrationRegime::Ioss->thresholdCurrencyFor($belfast))->toBe('GBP')
        ->and(TaxRegistrationRegime::Ioss->lowValueThresholdFor(regimeDestination('DE')))->toBe(150)
        ->and(TaxRegistrationRegime::Ioss->thresholdCurrencyFor(regimeDestination('DE')))->toBe('EUR');
});

it('measures VOEC and ARN per item, and VOEC only under its figure', function (): void {
    expect(TaxRegistrationRegime::Voec->measuresEachItem())->toBeTrue()
        ->and(TaxRegistrationRegime::Arn->measuresEachItem())->toBeTrue()
        ->and(TaxRegistrationRegime::Ioss->measuresEachItem())->toBeFalse()
        ->and(TaxRegistrationRegime::UkVat->measuresEachItem())->toBeFalse()
        ->and(TaxRegistrationRegime::Voec->exceedsThreshold(3000.0, 3000))->toBeTrue()
        ->and(TaxRegistrationRegime::Voec->exceedsThreshold(2999.99, 3000))->toBeFalse()
        ->and(TaxRegistrationRegime::Ioss->exceedsThreshold(150.0, 150))->toBeFalse()
        ->and(TaxRegistrationRegime::Ioss->exceedsThreshold(150.01, 150))->toBeTrue()
        ->and(TaxRegistrationRegime::Arn->exceedsThreshold(1000.0, 1000))->toBeFalse();
});

it('reads a regime from import input in any case', function (): void {
    expect(TaxRegistrationRegime::fromInput('UK_VAT'))->toBe(TaxRegistrationRegime::UkVat)
        ->and(TaxRegistrationRegime::fromInput(' ioss '))->toBe(TaxRegistrationRegime::Ioss)
        ->and(TaxRegistrationRegime::fromInput('eori'))->toBeNull();
});
