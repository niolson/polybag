<?php

namespace App\Services\ServiceInference;

use App\Models\CarrierAlias;

/**
 * The versioned tables the inference ladder reads.
 *
 * Committed under `resources/data/service-inference/` rather than seeded, because
 * an inference has to be re-derivable later: a package stamped with a ruleset
 * version must be comparable against the tables that produced it, and a database
 * row that has since been reseeded cannot offer that. `ruleset.json` carries the
 * version; each table carries its own upstream provenance.
 */
class ServiceRuleset
{
    /**
     * Every table, loaded together or not at all.
     *
     * Loaded as one unit and memoized per instance rather than cached per table.
     * Independent cache entries can outlive a deploy separately, which would let a
     * value derived from an old lookup table be stamped with a newly deployed
     * version -- and a value whose stamp says it came from rules it did not come
     * from is one that never gets re-derived, which defeats the versioning this
     * whole ruleset exists to provide.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $tables = null;

    /**
     * @param  string|null  $directory  Where the tables live. Defaults to the
     *                                  committed ruleset; overridden only by tests,
     *                                  which must never write to that one.
     */
    public function __construct(private readonly ?string $directory = null) {}

    /**
     * The version stamped onto anything this ruleset infers.
     */
    public function version(): string
    {
        return (string) $this->table('ruleset')['version'];
    }

    /**
     * The USPS product a Service Type Code names, or null if it names none.
     *
     * Null covers two different cases that behave the same way: an STC absent
     * from the appendix, and one whose description USPS writes irregularly enough
     * that the generator declined to derive a product. Both are inconclusive.
     */
    public function uspsProductForServiceTypeCode(string $stc): ?string
    {
        $code = $this->table('usps-impb-stc')['codes'][$stc] ?? null;

        return $code === null ? null : ($code['product'] ?? null);
    }

    /**
     * The UPS service a 1Z service level indicator names, or null if it names none.
     *
     * Null is the common case rather than the exceptional one. The table holds only
     * indicators backed by observed tracking-number/service pairs, so contract,
     * regional and international codes fall through here and let rung 2 answer --
     * which is the same discipline the USPS Service Type Codes follow.
     */
    public function upsServiceForServiceIndicator(string $indicator): ?string
    {
        return $this->table('ups-1z-service-indicator')['indicators'][strtoupper($indicator)] ?? null;
    }

    /**
     * Service tokens printed on a carrier's labels, keyed by the token as printed.
     *
     * Matched whole against a label field rather than searched for within one, so
     * no ordering is needed or meaningful here.
     *
     * @return array<string, string>
     */
    public function labelTokensFor(?string $carrier): array
    {
        $lookupKey = CarrierAlias::lookupKey($carrier);

        if ($lookupKey === '') {
            return [];
        }

        return $this->table('label-tokens')['carriers'][$lookupKey]['tokens'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function table(string $name): array
    {
        if ($this->tables === null) {
            $this->tables = [];

            $directory = $this->directory ?? resource_path('data/service-inference');

            foreach (['ruleset', 'usps-impb-stc', 'ups-1z-service-indicator', 'label-tokens'] as $table) {
                $path = "{$directory}/{$table}.json";

                $this->tables[$table] = json_decode(
                    (string) file_get_contents($path),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            }
        }

        return $this->tables[$name];
    }
}
