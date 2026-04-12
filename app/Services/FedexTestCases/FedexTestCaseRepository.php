<?php

namespace App\Services\FedexTestCases;

use App\DataTransferObjects\FedexTestCases\FedexTestCaseData;
use App\DataTransferObjects\FedexTestCases\FedexTestSuiteData;

class FedexTestCaseRepository
{
    /**
     * @throws \RuntimeException
     */
    public function load(string $region = 'us', string $suite = 'ship', ?string $path = null): FedexTestSuiteData
    {
        $fixturePath = $path ?: $this->defaultPath($region, $suite);

        if (! is_file($fixturePath)) {
            throw new \RuntimeException("Fixture file not found: {$fixturePath}");
        }

        $contents = file_get_contents($fixturePath);

        if ($contents === false) {
            throw new \RuntimeException("Unable to read fixture file: {$fixturePath}");
        }

        $data = json_decode($contents, true);

        if (! is_array($data)) {
            throw new \RuntimeException("Fixture file contains invalid JSON: {$fixturePath}");
        }

        $cases = $data['cases'] ?? $data['testCases'] ?? [];

        if (! is_array($cases)) {
            throw new \RuntimeException("Fixture file has no valid cases array: {$fixturePath}");
        }

        return new FedexTestSuiteData(
            carrier: strtolower((string) ($data['carrier'] ?? 'fedex')),
            region: strtolower((string) ($data['region'] ?? $region)),
            suite: strtolower((string) ($data['suite'] ?? $suite)),
            source: $data['source'] ?? data_get($data, '_meta.source'),
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : (is_array($data['_meta'] ?? null) ? $data['_meta'] : []),
            cases: collect($cases)
                ->filter(fn (mixed $case): bool => is_array($case))
                ->map(fn (array $case): FedexTestCaseData => FedexTestCaseData::fromArray($case))
                ->values()
                ->all(),
        );
    }

    public function defaultPath(string $region = 'us', string $suite = 'ship'): string
    {
        return resource_path("data/carrier-test-cases/fedex/{$region}/{$suite}.json");
    }
}
