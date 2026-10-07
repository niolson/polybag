<?php

use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\TrackingStatus;
use App\Enums\ValidationReason;
use App\Models\AddressValidationAnswer;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\Validation\ValidationQualityReport;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-10-07 12:00:00');
    $this->travelTo($this->now);
});

/**
 * A Shipment whose first Label was bought `$daysAgo` days ago, with one
 * Package per entry in `$packages` (attribute overrides on a shipped one).
 *
 * @param  list<array<string, mixed>>  $packages
 * @param  array<string, mixed>  $shipment
 */
function labelledShipment(int $daysAgo = 40, array $packages = [[]], array $shipment = []): Shipment
{
    $shipment = Shipment::factory()->create(['country' => 'US', ...$shipment]);
    $labelAt = now()->subDays($daysAgo);

    foreach ($packages as $i => $overrides) {
        $package = Package::factory()->shipped()->create(['shipment_id' => $shipment->id, ...$overrides]);
        $package->labels()->update(['purchased_at' => $labelAt->copy()->addMinutes($i)]);
    }

    return $shipment;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function answerFor(Shipment $shipment, int $daysAgo = 41, array $attributes = []): AddressValidationAnswer
{
    return AddressValidationAnswer::factory()->create([
        'shipment_id' => $shipment->id,
        'country' => $shipment->country,
        'created_at' => now()->subDays($daysAgo),
        ...$attributes,
    ]);
}

/**
 * @return array{data_points: int, answers: list<array<string, int|string>>, shadow: list<array<string, int|string>>}
 */
function qualityReport(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): array
{
    return app(ValidationQualityReport::class)->build($since, $until);
}

// --- Data points ---------------------------------------------------------------------

it('counts the live answer that was latest when the first Label was bought', function (): void {
    $shipment = labelledShipment(daysAgo: 40);
    answerFor($shipment, 45, ['deliverability' => Deliverability::No]);
    answerFor($shipment, 41)->update(['validator' => AddressValidator::Google]);
    answerFor($shipment, 39, ['deliverability' => Deliverability::Partial]);

    $report = qualityReport();

    expect($report['data_points'])->toBe(1)
        ->and($report['answers'])->toHaveCount(1)
        ->and($report['answers'][0])->toMatchArray([
            'validator' => 'google',
            'kind' => 'live',
            'country' => 'US',
            'verdict' => 'yes',
            'n' => 1,
        ]);
});

it('leaves out Shipments never labelled, never validated, or validated only after the Label', function (): void {
    answerFor(Shipment::factory()->create());
    labelledShipment();
    answerFor(labelledShipment(daysAgo: 40), daysAgo: 39);

    expect(qualityReport()['data_points'])->toBe(0);
});

it('reports an inconclusive answer by its reason', function (): void {
    answerFor(labelledShipment())->update([
        'outcome' => 'inconclusive',
        'deliverability' => null,
        'reason' => ValidationReason::MultipleCandidates,
    ]);

    expect(qualityReport()['answers'][0]['verdict'])->toBe('inconclusive:multiple_candidates');
});

it('uses the earliest Label across the Shipment\'s Packages', function (): void {
    $shipment = labelledShipment(daysAgo: 40);
    answerFor($shipment, 41, ['validator' => AddressValidator::Google]);
    answerFor($shipment, daysAgo: 20);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id])
        ->labels()->update(['purchased_at' => now()->subDays(10)]);

    expect(qualityReport()['answers'][0]['validator'])->toBe('google');
});

// --- Outcomes ------------------------------------------------------------------------

it('decides one outcome per Shipment', function (int $daysAgo, array $packages, string $expected): void {
    answerFor(labelledShipment($daysAgo, $packages), $daysAgo + 1);

    expect(collect(qualityReport()['answers'][0])->only(ValidationQualityReport::OUTCOMES)->filter()->keys()->all())
        ->toBe([$expected]);
})->with([
    'every Package delivered' => [5, [['delivered_at' => now()], ['delivered_at' => now()]], 'delivered'],
    'delivered status with no delivery time' => [5, [['tracking_status' => TrackingStatus::Delivered, 'delivered_at' => null]], 'delivered'],
    'one of two delivered' => [40, [['delivered_at' => now()], ['tracking_status' => TrackingStatus::InTransit]], 'pending'],
    'any Package returned, even with another delivered' => [5, [['delivered_at' => now()], ['tracking_status' => TrackingStatus::Returned]], 'returned'],
    'exception after 30 days' => [31, [['tracking_status' => TrackingStatus::Exception]], 'exception'],
    'exception within 30 days' => [29, [['tracking_status' => TrackingStatus::Exception]], 'pending'],
    'still in transit after 30 days' => [40, [['tracking_status' => TrackingStatus::InTransit]], 'pending'],
    'a voided Package does not hold back delivered' => [5, [['delivered_at' => now()], ['status' => 'void']], 'delivered'],
]);

// --- Edits and shadow answers ----------------------------------------------------------

it('counts later address edits and which parts they changed', function (): void {
    foreach ([['street_changed' => true], ['unit_changed' => true], []] as $parts) {
        answerFor(labelledShipment(), attributes: $parts === [] ? [] : [
            'address_changed_at' => now(),
            'street_changed' => false,
            'unit_changed' => false,
            'locality_changed' => false,
            'postcode_changed' => false,
            ...$parts,
        ]);
    }

    expect(qualityReport()['answers'][0])->toMatchArray([
        'n' => 3,
        'edited' => 2,
        'street_changed' => 1,
        'unit_changed' => 1,
        'locality_changed' => 0,
        'postcode_changed' => 0,
    ]);
});

it('reports a shadow answer beside its live answer and in the comparison', function (): void {
    $shipment = labelledShipment(daysAgo: 5, packages: [['delivered_at' => now()]], shipment: ['country' => 'DE']);
    $live = answerFor($shipment, 6, ['validator' => AddressValidator::Google, 'deliverability' => Deliverability::Verified]);
    AddressValidationAnswer::factory()->shadowOf($live)->create([
        'outcome' => 'inconclusive',
        'deliverability' => null,
        'reason' => ValidationReason::HouseNumberChanged,
        'street_differs' => null,
        'house_number_differs' => null,
        'city_differs' => null,
        'postcode_differs' => null,
    ]);
    $agreeing = answerFor(labelledShipment(daysAgo: 5, shipment: ['country' => 'DE']), 6, [
        'validator' => AddressValidator::Google,
        'deliverability' => Deliverability::Verified,
    ]);
    AddressValidationAnswer::factory()->shadowOf($agreeing)->create([
        'deliverability' => Deliverability::Verified,
        'postcode_differs' => true,
    ]);

    $report = qualityReport();

    expect(collect($report['answers'])->where('kind', 'shadow')->pluck('verdict')->sort()->values()->all())
        ->toBe(['inconclusive:house_number_changed', 'verified'])
        ->and($report['shadow'])->toBe([
            [
                'country' => 'DE', 'live_validator' => 'google', 'live_verdict' => 'verified', 'shadow_verdict' => 'inconclusive:house_number_changed',
                'n' => 1, 'returned' => 0, 'exception' => 0, 'delivered' => 1, 'pending' => 0,
                'street_differs' => 0, 'house_number_differs' => 0, 'city_differs' => 0, 'postcode_differs' => 0,
            ],
            [
                'country' => 'DE', 'live_validator' => 'google', 'live_verdict' => 'verified', 'shadow_verdict' => 'verified',
                'n' => 1, 'returned' => 0, 'exception' => 0, 'delivered' => 0, 'pending' => 1,
                'street_differs' => 0, 'house_number_differs' => 0, 'city_differs' => 0, 'postcode_differs' => 1,
            ],
        ]);
});

// --- Range and command ----------------------------------------------------------------

it('filters by when the first Label was bought', function (): void {
    answerFor(labelledShipment(daysAgo: 40), 41);
    answerFor(labelledShipment(daysAgo: 10), 11);

    expect(qualityReport(since: now()->subDays(20)->toImmutable())['data_points'])->toBe(1)
        ->and(qualityReport(until: now()->subDays(20)->toImmutable())['data_points'])->toBe(1)
        ->and(qualityReport()['data_points'])->toBe(2);
});

it('prints JSON with counts and no identifiers', function (): void {
    $shipment = labelledShipment(shipment: [
        'shipment_reference' => 'ORD-SYNTHETIC-1',
        'address1' => '742 Evergreen Terrace',
        'first_name' => 'Example',
    ]);
    answerFor($shipment);

    $this->artisan('address-validation:report', ['--json' => true, '--since' => '2026-01-01'])
        ->expectsOutputToContain('"data_points": 1')
        ->doesntExpectOutputToContain('ORD-SYNTHETIC-1')
        ->doesntExpectOutputToContain('Evergreen')
        ->doesntExpectOutputToContain('Example')
        ->doesntExpectOutputToContain('"shipment_id"')
        ->assertSuccessful();
});

it('prints tables marking rows too small to judge from', function (): void {
    answerFor(labelledShipment());

    $this->artisan('address-validation:report')
        ->expectsOutputToContain('1 Shipment(s) with a validator answer and a Label.')
        ->expectsOutputToContain('Rows marked * have fewer than 30')
        ->assertSuccessful();
});

it('refuses a date that is not YYYY-MM-DD', function (): void {
    $this->artisan('address-validation:report', ['--since' => 'last week'])
        ->expectsOutputToContain('--since must be a date in YYYY-MM-DD form.')
        ->assertFailed();
});

// --- Which part of the address an edit changed ---------------------------------------------

it('stamps which parts of the address an edit changed', function (array $edit, ?array $expected): void {
    $shipment = Shipment::factory()->create([
        'address1' => '1 Main St',
        'address2' => null,
        'city' => 'Springfield',
        'state_or_province' => 'IL',
        'postal_code' => '62701',
        'country' => 'US',
    ]);
    $answer = AddressValidationAnswer::factory()->create(['shipment_id' => $shipment->id]);

    $shipment->update($edit);
    $answer->refresh();

    expect($expected === null
        ? $answer->address_changed_at
        : $answer->only(['street_changed', 'unit_changed', 'locality_changed', 'postcode_changed']))
        ->toBe($expected);
})->with([
    'street' => [['address1' => '2 Main St'], ['street_changed' => true, 'unit_changed' => false, 'locality_changed' => false, 'postcode_changed' => false]],
    'unit added' => [['address2' => 'Apt 4'], ['street_changed' => false, 'unit_changed' => true, 'locality_changed' => false, 'postcode_changed' => false]],
    'city' => [['city' => 'Chicago'], ['street_changed' => false, 'unit_changed' => false, 'locality_changed' => true, 'postcode_changed' => false]],
    'state' => [['state_or_province' => 'MO'], ['street_changed' => false, 'unit_changed' => false, 'locality_changed' => true, 'postcode_changed' => false]],
    'postcode' => [['postal_code' => '62702'], ['street_changed' => false, 'unit_changed' => false, 'locality_changed' => false, 'postcode_changed' => true]],
    'country' => [['country' => 'CA'], ['street_changed' => true, 'unit_changed' => true, 'locality_changed' => true, 'postcode_changed' => true]],
    'case and spacing only' => [['address1' => '1  MAIN st'], null],
    'name' => [['first_name' => 'Renamed'], null],
    'phone' => [['phone' => '555-0100'], null],
    'email' => [['email' => 'someone@example.test'], null],
]);
