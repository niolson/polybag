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
