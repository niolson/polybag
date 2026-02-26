<?php

use App\Enums\DestinationZone;

it('has four cases', function (): void {
    expect(DestinationZone::cases())->toHaveCount(4);
});

it('continental US includes 48 states plus DC', function (): void {
    $states = DestinationZone::ContinentalUs->states();

    expect($states)->toHaveCount(49)
        ->and($states)->toContain('NY', 'CA', 'TX', 'FL', 'DC')
        ->and($states)->not->toContain('AK', 'HI');
});

it('non-continental US is exactly AK and HI', function (): void {
    $states = DestinationZone::NonContinentalUs->states();

    expect($states)->toEqualCanonicalizing(['AK', 'HI']);
});

it('US territories includes PR, GU, VI, AS, MP', function (): void {
    $states = DestinationZone::UsTerritories->states();

    expect($states)->toEqualCanonicalizing(['PR', 'GU', 'VI', 'AS', 'MP']);
});

it('international zone has no state codes', function (): void {
    expect(DestinationZone::International->states())->toBe([]);
});

it('returns correct labels', function (DestinationZone $zone, string $label): void {
    expect($zone->getLabel())->toBe($label);
})->with([
    [DestinationZone::ContinentalUs, 'Continental US'],
    [DestinationZone::NonContinentalUs, 'Non-Continental US'],
    [DestinationZone::UsTerritories, 'US Territories'],
    [DestinationZone::International, 'International'],
]);
