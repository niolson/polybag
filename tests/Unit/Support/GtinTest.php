<?php

use App\Support\Gtin;
use Database\Factories\ProductFactory;

it('accepts a GTIN of each length with a valid check digit', function (string $gtin): void {
    expect(Gtin::isValid($gtin))->toBeTrue();
})->with([
    'GTIN-8' => ['96385074'],
    'UPC-A' => ['036000291452'],
    'EAN-13' => ['4006381333931'],
    'GTIN-14' => ['10012345678902'],
]);

it('refuses anything that is not a GTIN', function (?string $value): void {
    expect(Gtin::isValid($value))->toBeFalse();
})->with([
    'wrong check digit' => ['036000291453'],
    'unsupported length' => ['12345678901'],
    'letters' => ['ABC-0012345'],
    'Code 128 internal label' => ['WH-000123'],
    'spaces' => ['0360 0029 145 2'],
    'empty' => [''],
    'null' => [null],
]);

it('gives factories a synthetic GTIN that validates', function (): void {
    expect(Gtin::isValid(ProductFactory::syntheticGtin()))->toBeTrue();
});
