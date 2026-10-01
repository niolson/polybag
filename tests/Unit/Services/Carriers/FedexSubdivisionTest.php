<?php

use App\Services\Carriers\FedexSubdivision;

it('translates stored subdivisions into FedEx codes', function (string $country, ?string $stored, ?string $expected): void {
    expect(FedexSubdivision::code($country, $stored))->toBe($expected);
})->with([
    'US code passes through' => ['US', 'WA', 'WA'],
    'Canadian code passes through' => ['CA', 'QC', 'QC'],
    'Puerto Rico' => ['PR', 'PR', 'PR'],
    'lower-case code' => ['us', ' wa ', 'WA'],
    'US value that is not a code' => ['US', 'Washington', null],
    'Mexican library code' => ['MX', 'N.L.', 'NL'],
    'Mexican code as stored upper-cased' => ['MX', 'MéX.', 'EM'],
    'Mexican code with accent upper-cased' => ['MX', 'MÉX.', 'EM'],
    'Mexican state name' => ['MX', 'Querétaro', 'QE'],
    'Mexico City' => ['MX', 'CDMX', 'DF'],
    'FedEx code already stored' => ['MX', 'QR', 'QR'],
    'Indian current name' => ['IN', 'ODISHA', 'OR'],
    'Indian former name' => ['IN', 'Uttaranchal', 'UA'],
    'Indian name with ampersand' => ['IN', 'Jammu & Kashmir', 'JK'],
    'Emirate with hyphen' => ['AE', 'RAS AL KHAIMAH', 'RA'],
    'unknown Mexican value' => ['MX', 'Atlantis', null],
    'country FedEx takes no state for' => ['AU', 'VIC', null],
    'empty value' => ['MX', '', null],
    'null value' => ['US', null, null],
]);
