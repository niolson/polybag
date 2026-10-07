<?php

use App\Enums\RecipientTaxIdType;

it('accepts a well-formed recipient tax ID', function (RecipientTaxIdType $type, string $id): void {
    expect($type->isValid($id))->toBeTrue()
        ->and($type->error($id))->toBeNull();
})->with([
    'CPF' => [RecipientTaxIdType::Cpf, '12345678909'],
    'CPF with punctuation' => [RecipientTaxIdType::Cpf, '123.456.789-09'],
    'CNPJ' => [RecipientTaxIdType::Cnpj, '11222333000181'],
    'CNPJ with punctuation' => [RecipientTaxIdType::Cnpj, '11.222.333/0001-81'],
    'alphanumeric CNPJ' => [RecipientTaxIdType::Cnpj, '12.ABC.345/01DE-35'],
    'PCCC' => [RecipientTaxIdType::Pccc, 'P123456789012'],
    'PCCC in lower case' => [RecipientTaxIdType::Pccc, 'p123456789012'],
    'VAT' => [RecipientTaxIdType::Vat, 'DE000000000'],
    'other' => [RecipientTaxIdType::Other, 'ANY-ID-0001'],
]);

it('rejects a malformed recipient tax ID', function (RecipientTaxIdType $type, ?string $id): void {
    expect($type->isValid($id))->toBeFalse()
        ->and($type->error($id))->toContain($type->getLabel());
})->with([
    'CPF with a wrong first check digit' => [RecipientTaxIdType::Cpf, '12345678919'],
    'CPF with a wrong second check digit' => [RecipientTaxIdType::Cpf, '12345678900'],
    'CPF of one repeated digit' => [RecipientTaxIdType::Cpf, '11111111111'],
    'CPF too short' => [RecipientTaxIdType::Cpf, '1234567890'],
    'CPF with a letter' => [RecipientTaxIdType::Cpf, '1234567890A'],
    'CNPJ with a wrong check digit' => [RecipientTaxIdType::Cnpj, '11222333000182'],
    'CNPJ of one repeated digit' => [RecipientTaxIdType::Cnpj, '00000000000000'],
    'CNPJ with a letter in the check digits' => [RecipientTaxIdType::Cnpj, '12ABC34501DE3A'],
    'alphanumeric CNPJ with a wrong check digit' => [RecipientTaxIdType::Cnpj, '12ABC34501DE36'],
    'PCCC without its P' => [RecipientTaxIdType::Pccc, '123456789012'],
    'PCCC with 11 digits' => [RecipientTaxIdType::Pccc, 'P12345678901'],
    'PCCC with 13 digits' => [RecipientTaxIdType::Pccc, 'P1234567890123'],
    'blank VAT' => [RecipientTaxIdType::Vat, '   '],
    'overlong other ID' => [RecipientTaxIdType::Other, str_repeat('9', 51)],
    'missing' => [RecipientTaxIdType::Cpf, null],
]);

it('stores CPF and CNPJ without punctuation and a PCCC in upper case', function (): void {
    expect(RecipientTaxIdType::Cpf->normalize('123.456.789-09'))->toBe('12345678909')
        ->and(RecipientTaxIdType::Cnpj->normalize('12.abc.345/01de-35'))->toBe('12ABC34501DE35')
        ->and(RecipientTaxIdType::Pccc->normalize('p 1234-5678-9012'))->toBe('P123456789012')
        ->and(RecipientTaxIdType::Vat->normalize('  DE 000 000 000 '))->toBe('DE 000 000 000');
});

it('reads a type from import input in any case', function (): void {
    expect(RecipientTaxIdType::fromInput(' CPF '))->toBe(RecipientTaxIdType::Cpf)
        ->and(RecipientTaxIdType::fromInput(RecipientTaxIdType::Pccc))->toBe(RecipientTaxIdType::Pccc)
        ->and(RecipientTaxIdType::fromInput('ssn'))->toBeNull()
        ->and(RecipientTaxIdType::fromInput(null))->toBeNull();
});
