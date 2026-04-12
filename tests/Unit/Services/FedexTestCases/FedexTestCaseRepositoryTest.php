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
