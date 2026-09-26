<?php

namespace App\Models;

use App\Enums\PostageSourceKind;
use Database\Factories\SourceServiceMappingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One postage source's name for a catalog service — ADR-0006 decision 2.
 *
 * `(source kind, external carrier id, external service id) → carrier_service_id`.
 * An Amazon row is inward: several identifiers may name one service. A Shopify
 * row is also outward, because a purchase sends exactly one code, so the
 * database refuses a second Shopify row for a service.
 *
 * Environment and marketplace are deliberately not part of the key. A name is
 * not an approval: if Amazon's sandbox and production both report
 * `USPS/USPS_GROUND_ADVANTAGE`, that is one service under one name.
 *
 * @property PostageSourceKind $source_kind
 * @property string $external_carrier_id
 * @property string $external_service_id
 * @property int $carrier_service_id
 */
class SourceServiceMapping extends Model
{
    /** @use HasFactory<SourceServiceMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'source_kind',
        'external_carrier_id',
        'external_service_id',
        'carrier_service_id',
    ];

    protected function casts(): array
    {
        return [
            'source_kind' => PostageSourceKind::class,
        ];
    }

    /**
     * @return BelongsTo<CarrierService, $this>
     */
    public function carrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class);
    }

    /**
     * How a person reads this mapping: "Amazon Buy Shipping ONTRAC / ONTRAC_GROUND".
     */
    public function describe(): string
    {
        return "{$this->source_kind->label()} {$this->external_carrier_id} / {$this->external_service_id}";
    }

    /**
     * Point one source identity at a service, replacing whatever it named.
     *
     * Not an upsert: MySQL's `ON DUPLICATE KEY UPDATE` fires on any unique key,
     * so a second Shopify code for a service would quietly rewrite the first
     * row instead of being refused. Two people mapping the same new identity
     * at once is settled by the external key, and the loser sees an error.
     */
    public static function map(PostageSourceKind $kind, string $externalCarrierId, string $externalServiceId, int $carrierServiceId): self
    {
        return static::query()->updateOrCreate(
            [
                'source_kind' => $kind,
                'external_carrier_id' => $externalCarrierId,
                'external_service_id' => $externalServiceId,
            ],
            ['carrier_service_id' => $carrierServiceId],
        );
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForIdentity(Builder $query, PostageSourceKind $kind, string $externalCarrierId, string $externalServiceId): void
    {
        $query->where('source_kind', $kind)
            ->where('external_carrier_id', $externalCarrierId)
            ->where('external_service_id', $externalServiceId);
    }

    /**
     * Mappings for a batch of one source's identities, in one query — what a
     * quote needs for every offer it returns.
     *
     * @param  iterable<array{0: string, 1: string}>  $identities  [external carrier id, external service id]
     * @return Collection<string, self> keyed by {@see key()}
     */
    public static function forIdentities(PostageSourceKind $kind, iterable $identities): Collection
    {
        $identities = collect($identities);
        $requested = $identities
            ->mapWithKeys(fn (array $identity): array => [static::key($identity[0], $identity[1]) => true]);

        if ($requested->isEmpty()) {
            return collect();
        }

        // Independent whereIn clauses match cross terms nobody asked about;
        // they are dropped below rather than written as a tuple per identity.
        return self::query()
            ->where('source_kind', $kind)
            ->whereIn('external_carrier_id', $identities->pluck(0)->unique()->all())
            ->whereIn('external_service_id', $identities->pluck(1)->unique()->all())
            ->with('carrierService.carrier')
            ->get()
            ->keyBy(fn (self $mapping): string => static::key($mapping->external_carrier_id, $mapping->external_service_id))
            ->filter(fn (self $mapping, string $key): bool => $requested->has($key))
            ->toBase();
    }

    /**
     * One source's rows for these catalog services, in one query, keyed by
     * service id — the outward direction, what a purchase sends for a service.
     * Only Shopify's rows are outward, so a service has at most one of them.
     *
     * @param  iterable<int>  $carrierServiceIds
     * @return Collection<int, self> keyed by carrier service id
     */
    public static function forServices(PostageSourceKind $kind, iterable $carrierServiceIds): Collection
    {
        $ids = collect($carrierServiceIds)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return self::query()
            ->where('source_kind', $kind)
            ->whereIn('carrier_service_id', $ids->all())
            ->get()
            ->keyBy('carrier_service_id')
            ->toBase();
    }

    public static function key(string $externalCarrierId, string $externalServiceId): string
    {
        return $externalCarrierId.'|'.$externalServiceId;
    }
}
