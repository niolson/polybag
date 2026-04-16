<?php

use App\DataTransferObjects\FedexTestCases\FedexTestCaseData;
use App\Services\FedexTestCases\FedexTestCaseRepository;

it('loads the default FedEx US ship fixture suite', function (): void {
    $suite = app(FedexTestCaseRepository::class)->load();

    expect($suite->carrier)->toBe('fedex')
        ->and($suite->region)->toBe('us')
        ->and($suite->suite)->toBe('ship')
        ->and($suite->cases)->not->toBeEmpty()
        ->and($suite->cases[0])->toBeInstanceOf(FedexTestCaseData::class);
});

it('loads the default FedEx CA suites', function (): void {
    $repository = app(FedexTestCaseRepository::class);

    $shipSuite = $repository->load(region: 'ca', suite: 'ship');
    $rateSuite = $repository->load(region: 'ca', suite: 'rate');

    expect($shipSuite->region)->toBe('ca')
        ->and($shipSuite->suite)->toBe('ship')
        ->and($shipSuite->cases)->toHaveCount(5)
        ->and($shipSuite->caseIds())->toContain('IntegratorCA01', 'IntegratorCA05')
        ->and($rateSuite->region)->toBe('ca')
        ->and($rateSuite->suite)->toBe('rate')
        ->and($rateSuite->cases)->toHaveCount(1)
        ->and($rateSuite->caseIds())->toBe(['IntegratorCA06']);
});

it('loads the default FedEx LAC suites', function (): void {
    $repository = app(FedexTestCaseRepository::class);

    $shipSuite = $repository->load(region: 'lac', suite: 'ship');
    $rateSuite = $repository->load(region: 'lac', suite: 'rate');

    expect($shipSuite->region)->toBe('lac')
        ->and($shipSuite->suite)->toBe('ship')
        ->and($shipSuite->cases)->toHaveCount(3)
        ->and($shipSuite->caseIds())->toContain('IntegratorLAC01', 'IntegratorLAC03')
        ->and($rateSuite->region)->toBe('lac')
        ->and($rateSuite->suite)->toBe('rate')
        ->and($rateSuite->cases)->toHaveCount(1)
        ->and($rateSuite->caseIds())->toBe(['IntegratorLAC04']);
});

it('loads a fixture from an explicit path', function (): void {
    $fixturePath = tempnam(sys_get_temp_dir(), 'fedex-suite-');

    file_put_contents($fixturePath, json_encode([
        'carrier' => 'fedex',
        'region' => 'ca',
        'suite' => 'ship',
        'source' => 'Unit test',
        'cases' => [
            [
                'id' => 'Case01',
                'description' => 'Test case',
                'request_type' => 'create_shipment',
                'supported' => true,
                'request' => ['labelResponseOptions' => 'LABEL'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $suite = app(FedexTestCaseRepository::class)->load(path: $fixturePath);

    expect($suite->region)->toBe('ca')
        ->and($suite->cases)->toHaveCount(1)
        ->and($suite->cases[0]->id)->toBe('Case01');

    @unlink($fixturePath);
});

it('throws when the fixture file does not exist', function (): void {
    app(FedexTestCaseRepository::class)->load(path: '/tmp/does-not-exist-fedex-fixture.json');
})->throws(RuntimeException::class, 'Fixture file not found');
