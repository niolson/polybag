<?php

use App\Enums\DutiesTerms;
use App\Support\ExportItn;

it('accepts an ITN of X and 14 digits, in any case and spacing', function (string $itn): void {
    expect(ExportItn::isValid($itn))->toBeTrue()
        ->and(ExportItn::error($itn))->toBeNull();
})->with([
    'X00000000000001',
    'x00000000000001',
    ' X 0000 0000 0000 01 ',
]);

it('rejects anything else as an ITN', function (?string $itn): void {
    expect(ExportItn::isValid($itn))->toBeFalse()
        ->and(ExportItn::error($itn))->toContain(ExportItn::FORMAT);
})->with([
    'no X' => '00000000000001',
    '13 digits' => 'X0000000000001',
    '15 digits' => 'X000000000000001',
    'an exemption, not an ITN' => 'NO EEI 30.37(a)',
    'missing' => null,
]);

it('stores an ITN in upper case without spaces', function (): void {
    expect(ExportItn::normalize(' x 0000 0000 0000 01'))->toBe('X00000000000001');
});

it('reads duties terms from import input in any case', function (): void {
    expect(DutiesTerms::fromInput('DDP'))->toBe(DutiesTerms::Ddp)
        ->and(DutiesTerms::fromInput(' ddu '))->toBe(DutiesTerms::Ddu)
        ->and(DutiesTerms::fromInput('dap'))->toBeNull()
        ->and(DutiesTerms::fromInput(null))->toBeNull();
});
