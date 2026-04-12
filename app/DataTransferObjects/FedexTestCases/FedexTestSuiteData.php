<?php

namespace App\DataTransferObjects\FedexTestCases;

use Illuminate\Support\Collection;

readonly class FedexTestSuiteData
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, FedexTestCaseData>  $cases
     */
    public function __construct(
        public string $carrier,
        public string $region,
        public string $suite,
        public ?string $source,
        public array $meta,
        public array $cases,
    ) {}

    /**
     * @return Collection<int, FedexTestCaseData>
     */
    public function cases(): Collection
    {
        return collect($this->cases);
    }

    /**
     * @return array<int, string>
     */
    public function caseIds(): array
    {
        return $this->cases()->pluck('id')->all();
    }
}
