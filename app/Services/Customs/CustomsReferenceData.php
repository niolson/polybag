<?php

namespace App\Services\Customs;

use App\Enums\CustomsFindingSeverity;
use App\Enums\RecipientTaxIdType;

/**
 * The sourced data files `CustomsReadiness` reads from
 * `resources/data/customs/`: where an export filing is always required
 * (`export-filing.json`) and where the recipient's tax ID is
 * (`recipient-tax-id.json`).
 *
 * Data, not code, so a change of rule is a sourced line in a JSON file. The
 * directory is injectable so a test can supply its own entries.
 */
class CustomsReferenceData
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly ?string $directory = null) {}

    /**
     * Destinations that need an ITN whatever the customs total, as upper-case
     * ISO country codes.
     *
     * @return list<string>
     */
    public function alwaysFileDestinations(): array
    {
        $destinations = $this->read('export-filing.json')['destinations'] ?? [];

        return array_values(array_map(strtoupper(...), array_keys(is_array($destinations) ? $destinations : [])));
    }

    /**
     * The recipient tax ID rule for a destination, or null when it needs none.
     *
     * @return array{severity: CustomsFindingSeverity, types: list<RecipientTaxIdType>, consumersOnly: bool}|null
     */
    public function recipientTaxIdRule(string $country): ?array
    {
        $entry = ($this->read('recipient-tax-id.json')['destinations'] ?? [])[strtoupper(trim($country))] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $types = array_values(array_filter(array_map(
            fn (mixed $type): ?RecipientTaxIdType => RecipientTaxIdType::fromInput($type),
            (array) ($entry['types'] ?? []),
        )));

        return [
            'severity' => CustomsFindingSeverity::tryFrom((string) ($entry['severity'] ?? 'block')) ?? CustomsFindingSeverity::Block,
            'types' => $types,
            'consumersOnly' => (bool) ($entry['consumers_only'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        return $this->cache[$file] ??= $this->load($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $file): array
    {
        $path = rtrim($this->directory ?? resource_path('data/customs'), '/').'/'.$file;
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($decoded)) {
            throw new \RuntimeException("Customs reference data {$file} is missing or is not valid JSON.");
        }

        return $decoded;
    }
}
