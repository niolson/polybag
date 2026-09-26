<?php

namespace App\Services\PostageSources;

use App\DataTransferObjects\PostageSources\ServiceObservation;
use App\Enums\SourceEnvironment;
use App\Models\ObservedService;
use App\Models\SourceServiceMapping;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Writes what a postage source reported into the durable identity store.
 *
 * Deduplication is the whole job. A single Amazon `getRates` returns a handful
 * of eligible offers and upwards of a hundred ineligible ones, and every quote
 * for every parcel returns broadly the same catalog again — so this runs on the
 * hot path of the Ship page and must not grow a query per service.
 *
 * Nothing here creates a `Carrier` or a `CarrierService`. ADR-0003 decision 2
 * is explicit that promotion into the authored catalog is a human act; this
 * class only ever records that an identity was seen.
 *
 * Nor does it name anything. A mapping is one {@see SourceServiceMapping} row
 * per service, written only by {@see ObservedServiceMapper}, so a new sighting
 * of a mapped service is mapped the moment it is inserted, with nothing to copy.
 */
class ObservedServiceRecorder
{
    /**
     * @param  iterable<ServiceObservation>  $observations
     * @return Collection<int, ObservedService>
     */
    public function record(iterable $observations): Collection
    {
        // The same identity can appear twice in one response — Amazon returned
        // three USPS Priority Mail Express variants differing only in flat-rate
        // packaging. Collapse them, letting an eligible mention outrank an
        // ineligible one for the same identity.
        $observations = collect($observations)->reduce(
            function (Collection $unique, ServiceObservation $observation): Collection {
                $key = $this->identityKey($observation);

                if (! $unique->get($key)?->eligible) {
                    $unique->put($key, $observation);
                }

                return $unique;
            },
            collect(),
        )->values();

        if ($observations->isEmpty()) {
            return collect();
        }

        $environment = SourceEnvironment::current();
        $now = now();

        $existing = $this->existingIdentities($observations, $environment);

        $this->insertMissing($observations, $existing, $environment, $now);

        // Re-read so newly inserted rows join the ones already on file; both
        // then take the same increment, which is what keeps observation_count
        // meaning "times seen" rather than "times seen since it existed".
        $rows = $this->existingIdentities($observations, $environment);

        $this->touch($observations, $rows, $now);
        $this->refreshChangedNames($observations, $rows);

        return $this->existingIdentities($observations, $environment)->values();
    }

    /**
     * The rows already on file for exactly these identities.
     *
     * The `whereIn` clauses are independent, so the database is being asked a
     * broader question than the one being answered: carriers {A, B} crossed
     * with services {X, Y} matches an existing (A, Y) that nobody observed.
     * That is deliberate — one indexed query beats a hundred tuple predicates —
     * but the cross terms have to be dropped before the result is used, or an
     * unrelated service is touched, counted and returned to the caller.
     *
     * @param  Collection<int, ServiceObservation>  $observations
     * @return Collection<string, ObservedService> keyed by identity
     */
    private function existingIdentities(Collection $observations, SourceEnvironment $environment): Collection
    {
        $requested = $observations
            ->map(fn (ServiceObservation $observation): string => $this->identityKey($observation))
            ->flip();

        return ObservedService::query()
            ->whereIn('source', $observations->pluck('source')->unique()->all())
            ->where('environment', $environment)
            ->whereIn('marketplace', $observations->map(fn (ServiceObservation $o): string => $o->marketplace ?? '')->unique()->all())
            ->whereIn('external_carrier_id', $observations->pluck('externalCarrierId')->unique()->all())
            ->whereIn('external_service_id', $observations->pluck('externalServiceId')->unique()->all())
            ->get()
            ->keyBy(fn (ObservedService $service): string => implode('|', [
                $service->source,
                $service->marketplace,
                $service->external_carrier_id,
                $service->external_service_id,
            ]))
            ->filter(fn (ObservedService $service, string $key): bool => $requested->has($key));
    }

    /**
     * @param  Collection<int, ServiceObservation>  $observations
     * @param  Collection<string, ObservedService>  $existing
     */
    private function insertMissing(
        Collection $observations,
        Collection $existing,
        SourceEnvironment $environment,
        CarbonInterface $now,
    ): void {
        $new = $observations
            ->reject(fn (ServiceObservation $observation): bool => $existing->has($this->identityKey($observation)))
            ->values();

        if ($new->isEmpty()) {
            return;
        }

        // insertOrIgnore rather than insert: two packers quoting the same
        // parcel at once both find the identity missing, and the unique
        // index is what settles it. Losing that race is not an error.
        ObservedService::query()->insertOrIgnore(
            $new->map(fn (ServiceObservation $observation): array => [
                'source' => $observation->source,
                'environment' => $environment->value,
                'marketplace' => $observation->marketplace ?? '',
                'external_carrier_id' => $observation->externalCarrierId,
                'external_carrier_name' => $observation->externalCarrierName,
                'external_service_id' => $observation->externalServiceId,
                'external_service_name' => $observation->externalServiceName,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                // Zero, not one: the increment below is what counts this
                // sighting, and it applies to new and existing rows alike.
                'observation_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );
    }

    /**
     * @param  Collection<int, ServiceObservation>  $observations
     * @param  Collection<string, ObservedService>  $rows
     */
    private function touch(Collection $observations, Collection $rows, CarbonInterface $now): void
    {
        [$eligible, $ineligible] = $observations->partition(
            fn (ServiceObservation $observation): bool => $observation->eligible
        );

        $idsFor = fn (Collection $group): array => $group
            ->map(fn (ServiceObservation $o): ?int => $rows->get($this->identityKey($o))?->id)
            ->filter()
            ->values()
            ->all();

        if (($ids = $idsFor($eligible)) !== []) {
            $this->reportFirstOffered($rows->whereIn('id', $ids)->whereNull('last_eligible_at'), $now);

            ObservedService::query()->whereIn('id', $ids)->increment('observation_count', 1, [
                'last_seen_at' => $now,
                'last_eligible_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (($ids = $idsFor($ineligible)) !== []) {
            // last_eligible_at is deliberately untouched: a service that was
            // buyable last week and is not today keeps the date that says so.
            ObservedService::query()->whereIn('id', $ids)->increment('observation_count', 1, [
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Log an unmapped service the first time a source offers it as buyable —
     * `carrier-catalog-reset/11`.
     *
     * The Map Carrier Services page lists it either way, and deliberately
     * raises nothing (ADR-0003 decision 8). This is the one line that says a
     * new service became real, for whoever is watching the log.
     *
     * Two writes, not one per row: a first sighting of a production reply
     * offers several services at once. The rows still empty are claimed in one
     * update, then read back by the value it wrote, so a second packer quoting
     * the same parcel later finds nothing left to claim. Two quotes landing in
     * the same second can both log it; that costs a repeated line, not a
     * missed one. The increment that follows writes the same value again.
     *
     * @param  Collection<string, ObservedService>  $firstOffered  rows as read before this sighting
     */
    private function reportFirstOffered(Collection $firstOffered, CarbonInterface $now): void
    {
        if ($firstOffered->isEmpty()) {
            return;
        }

        $ids = $firstOffered->pluck('id')->all();

        ObservedService::query()
            ->whereIn('id', $ids)
            ->whereNull('last_eligible_at')
            ->update(['last_eligible_at' => $now]);

        ObservedService::query()
            ->whereIn('id', $ids)
            ->where('last_eligible_at', $now)
            ->unmapped()
            ->get()
            ->each(fn (ObservedService $service) => logger()->info('Unmapped postage service offered for the first time', [
                'source' => $service->source,
                'environment' => $service->environment->value,
                'marketplace' => $service->marketplace,
                'external_carrier_id' => $service->external_carrier_id,
                'external_carrier_name' => $service->external_carrier_name,
                'external_service_id' => $service->external_service_id,
                'external_service_name' => $service->external_service_name,
            ]));
    }

    /**
     * Carry through a renamed service or carrier.
     *
     * Normally updates nothing — names are stable — so this stays a per-row
     * write for the rare case rather than a column in the bulk update.
     *
     * @param  Collection<int, ServiceObservation>  $observations
     * @param  Collection<string, ObservedService>  $rows
     */
    private function refreshChangedNames(Collection $observations, Collection $rows): void
    {
        foreach ($observations as $observation) {
            $row = $rows->get($this->identityKey($observation));

            if (! $row) {
                continue;
            }

            $changed = array_filter([
                'external_carrier_name' => $observation->externalCarrierName,
                'external_service_name' => $observation->externalServiceName,
            ], fn (?string $name, string $column): bool => $name !== null && $name !== $row->{$column},
                ARRAY_FILTER_USE_BOTH);

            if ($changed !== []) {
                ObservedService::query()->whereKey($row->id)->update($changed);
            }
        }
    }

    private function identityKey(ServiceObservation $observation): string
    {
        return implode('|', [
            $observation->source,
            $observation->marketplace ?? '',
            $observation->externalCarrierId,
            $observation->externalServiceId,
        ]);
    }
}
