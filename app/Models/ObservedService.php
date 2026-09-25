<?php

namespace App\Models;

use App\Enums\PostageSourceKind;
use App\Enums\SourceEnvironment;
use App\Services\PostageSources\ObservedServiceMapper;
use Database\Factories\ObservedServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A durable service identity a postage source has reported at least once.
 *
 * ADR-0003 decision 2, the observation half. This is what Amazon says exists;
 * {@see CarrierService} is what we have decided to call a service. Keeping them
 * apart is what lets an offer for OnTrac — a carrier we hold no row for, no
 * account with, and no adapter — be recorded at all.
 *
 * Being observed says nothing about whether automation may spend money on it.
 * That is approval, and it is a separate concept again (ADR-0003 decision 3).
 *
 * Nor does it say what we call the service. That is a {@see SourceServiceMapping}
 * row, one per service rather than one per sighting (`carrier-catalog-reset/14`).
 *
 * @property string $source
 * @property SourceEnvironment $environment
 * @property string $marketplace
 * @property string $external_carrier_id
 * @property string|null $external_carrier_name
 * @property string $external_service_id
 * @property string|null $external_service_name
 * @property int $observation_count
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $last_eligible_at
 * @property array<string, mixed>|null $additional_inputs_schema the JSON schema
 *                                                               a purchase of this service must satisfy in `additionalInputs`, recorded
 *                                                               the first time a rate for it said it required them. Null until then —
 *                                                               which, for every service seen so far, is still.
 * @property Carbon|null $additional_inputs_schema_seen_at
 * @property int|null $mapped_carrier_service_id only when selected {@see scopeWithMapping()}
 */
class ObservedService extends Model
{
    /** @use HasFactory<ObservedServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'source',
        'environment',
        'marketplace',
        'external_carrier_id',
        'external_carrier_name',
        'external_service_id',
        'external_service_name',
        'first_seen_at',
        'last_seen_at',
        'last_eligible_at',
        'observation_count',
        'additional_inputs_schema',
        'additional_inputs_schema_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'environment' => SourceEnvironment::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_eligible_at' => 'datetime',
            'observation_count' => 'integer',
            'additional_inputs_schema' => 'array',
            'additional_inputs_schema_seen_at' => 'datetime',
        ];
    }

    /**
     * The kind of postage source that reported this identity.
     */
    public function sourceKind(): PostageSourceKind
    {
        return PostageSourceKind::from($this->source);
    }

    /**
     * The catalog service this identity is mapped to, if anyone mapped it.
     *
     * One query; a quote reads mappings in bulk through
     * {@see SourceServiceMapping::forIdentities()} instead.
     */
    public function mapping(): ?SourceServiceMapping
    {
        return SourceServiceMapping::query()
            ->forIdentity($this->sourceKind(), $this->external_carrier_id, $this->external_service_id)
            ->first();
    }

    public function isMapped(): bool
    {
        return SourceServiceMapping::query()
            ->forIdentity($this->sourceKind(), $this->external_carrier_id, $this->external_service_id)
            ->exists();
    }

    /**
     * The mapped service, through the `mapped_carrier_service_id` that
     * {@see scopeWithMapping()} selects. Null on a row loaded without it.
     *
     * @return BelongsTo<CarrierService, $this>
     */
    public function mappedCarrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class, 'mapped_carrier_service_id');
    }

    /**
     * Whether this identity has ever been offered as buyable, as opposed to
     * only ever appearing in an `ineligibleRates` catalog.
     */
    public function hasBeenEligible(): bool
    {
        return $this->last_eligible_at !== null;
    }

    /**
     * How the source names it, for a human deciding what to map it to.
     */
    public function displayName(): string
    {
        $carrier = $this->external_carrier_name ?? $this->external_carrier_id;
        $service = $this->external_service_name ?? $this->external_service_id;

        return "{$carrier} — {$service}";
    }

    /**
     * Select each row's mapped service as `mapped_carrier_service_id`, so a
     * page of observations can show, filter and eager-load its mappings
     * without a query per row.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWithMapping(Builder $query): void
    {
        $query->select('observed_services.*')->addSelect([
            'mapped_carrier_service_id' => SourceServiceMapping::query()
                ->select('carrier_service_id')
                ->whereColumn('source_service_mappings.source_kind', 'observed_services.source')
                ->whereColumn('source_service_mappings.external_carrier_id', 'observed_services.external_carrier_id')
                ->whereColumn('source_service_mappings.external_service_id', 'observed_services.external_service_id')
                ->limit(1),
        ]);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnmapped(Builder $query): void
    {
        $query->whereNotExists(fn ($mappings) => $mappings
            ->from('source_service_mappings')
            ->whereColumn('source_service_mappings.source_kind', 'observed_services.source')
            ->whereColumn('source_service_mappings.external_carrier_id', 'observed_services.external_carrier_id')
            ->whereColumn('source_service_mappings.external_service_id', 'observed_services.external_service_id'));
    }

    /**
     * Every row naming the same service, whatever world it was seen in.
     *
     * Deliberately narrower than the five-part identity this table is keyed
     * on: environment and marketplace are dropped. Amazon's sandbox and
     * production catalogs disagree about what is *offered*, and an approval to
     * spend money is scoped to one of them (ADR-0003 decision 3) — but a name
     * is not an approval. If both worlds report
     * `USPS/USPS_GROUND_ADVANTAGE`, that is one service under one name.
     *
     * This is the scope a {@see SourceServiceMapping} covers, which
     * {@see ObservedServiceMapper} reports back to the person who mapped it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSameService(Builder $query, string $source, string $externalCarrierId, string $externalServiceId): void
    {
        $query->where('source', $source)
            ->where('external_carrier_id', $externalCarrierId)
            ->where('external_service_id', $externalServiceId);
    }

    /**
     * The same scope as {@see scopeSameService()}, as a key for grouping rows
     * in memory.
     */
    public static function serviceKey(string $source, string $externalCarrierId, string $externalServiceId): string
    {
        return implode('|', [$source, $externalCarrierId, $externalServiceId]);
    }
}
