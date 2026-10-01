<?php

use App\Services\AddressReferenceService;

it('normalizes countries from names and alternate codes', function (): void {
    $service = app(AddressReferenceService::class);

    expect($service->normalizeCountry('United States'))->toBe('US')
        ->and($service->normalizeCountry('usa'))->toBe('US')
        ->and($service->normalizeCountry('Canada'))->toBe('CA');
});

it('normalizes subdivisions from names and ISO-style identifiers', function (): void {
    $service = app(AddressReferenceService::class);

    expect($service->normalizeSubdivision('US', 'California'))->toBe('CA')
        ->and($service->normalizeSubdivision('US', 'US-NY'))->toBe('NY')
        ->and($service->normalizeSubdivision('CA', 'Ontario'))->toBe('ON');
});

it('returns subdivision options for countries with predefined administrative areas', function (): void {
    $service = app(AddressReferenceService::class);
    $options = $service->getSubdivisionOptions('US');

    expect($options)->toHaveKey('CA')
        ->and($options['CA'])->toBe('California');
});

it('normalizes the military subdivision names carriers and channels use', function (): void {
    // The addressing library lists these as "Armed Forces (AE)", but Shopify and
    // USPS spell out the region. Without the alias the full name survives and
    // USPS rejects the label, since it requires a two-letter state.
    $service = app(AddressReferenceService::class);

    expect($service->normalizeSubdivision('US', 'Armed Forces Europe'))->toBe('AE')
        ->and($service->normalizeSubdivision('US', 'Armed Forces Africa'))->toBe('AE')
        ->and($service->normalizeSubdivision('US', 'Armed Forces Canada'))->toBe('AE')
        ->and($service->normalizeSubdivision('US', 'Armed Forces Middle East'))->toBe('AE')
        ->and($service->normalizeSubdivision('US', 'Armed Forces Americas'))->toBe('AA')
        ->and($service->normalizeSubdivision('US', 'Armed Forces Pacific'))->toBe('AP')
        ->and($service->normalizeSubdivision('US', 'AE'))->toBe('AE');
});

it('leaves the subdivision option list untouched when aliasing', function (): void {
    $service = app(AddressReferenceService::class);
    $options = $service->getSubdivisionOptions('US');

    expect($options)->toHaveKey('AE')
        ->and($options['AE'])->toBe('Armed Forces (AE)')
        ->and($options)->not->toHaveKey('Armed Forces Europe');
});

it('upper-cases accented subdivision codes whole', function (): void {
    // The library codes Estado de México as "Méx."; a byte-wise upper-case
    // stored it as "MéX.".
    $service = app(AddressReferenceService::class);

    expect($service->getSubdivisionOptions('MX'))->toHaveKey('MÉX.')
        ->and($service->normalizeSubdivision('MX', 'Estado de México'))->toBe('MÉX.')
        ->and($service->normalizeSubdivision('MX', 'MéX.'))->toBe('MÉX.');
});
