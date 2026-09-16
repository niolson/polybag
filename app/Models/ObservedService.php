<?php

namespace App\Models;

use App\Enums\SourceEnvironment;
use App\Filament\Pages\UnmappedObservedServices;
use App\Services\PostageSources\ObservedServiceMapper;
use App\Services\PostageSources\ObservedServiceRecorder;
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
 * @property string $source
 * @property SourceEnvironment $environment
 * @property string $marketplace
 * @property string $external_carrier_id
 * @property string|null $external_carrier_name
 * @property string $external_service_id
 * @property string|null $external_service_name
 * @property int|null $carrier_service_id
 * @property int $observation_count
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $last_eligible_at
 * @property array<string, mixed>|null $additional_inputs_schema the JSON schema
 *                                                               a purchase of this service must satisfy in `additionalInputs`, recorded
 *                                                               the first time a rate for it said it required them. Null until then —
 *                                                               which, for every service seen so far, is still.
 * @property Carbon|null $additional_inputs_schema_seen_at
 * @property-read int|null $environment_approvals_count how many clients have
 *     approved this service for automated spending, in the world this row was
 *     seen in. Not a column and not a relation — approvals are keyed on the
 *     service identity rather than on the sighting, so there is no foreign key
 *     to count through. Present only where a query selects it;
 *     {@see UnmappedObservedServices} does, with a correlated subquery.
 * @property-read int|null $service_approvals_count the same count across every
 *     environment — what unmapping this service would withdraw, since a mapping
 *     spans worlds and the revocation that follows it spans them too.
 */
class ObservedService extends Model
{
    /** @use HasFactory<ObservedServiceFactory> */
    use HasFactory;

    /**
     * Serializes the two writers of `carrier_service_id`.
     *
     * A mapping is stored on every sighting of a service rather than once
     * beside it, which makes reads a plain foreign key and makes writes two
     * parties: {@see ObservedServiceMapper}
     * changes the mapping across existing rows, and
     * {@see ObservedServiceRecorder} copies the
     * current mapping onto a row it is inserting. Both are read-then-write, and
     * interleaved they disagree permanently — a mapping made between the
     * recorder's read and its insert never reaches the new row, and an unmapping
     * in the same window is undone by it. Neither leaves a trace, and nothing
     * revisits the row.
     *
     * One name, held briefly by both. Deliberately not per-service: a quote can
     * bring back a hundred identities at once, and the alternative to one
     * uncontended lock is a hundred.
     */
    public const MAPPING_LOCK = 'observed-service-mapping';

    protected $fillable = [
        'source',
        'environment',
        'marketplace',
        'external_carrier_id',
        'external_carrier_name',
        'external_service_id',
        'external_service_name',
        'carrier_service_id',
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
     * @return BelongsTo<CarrierService, $this>
     */
    public function carrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class);
    }

    public function isMapped(): bool
    {
        return $this->carrier_service_id !== null;
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
     * @param  Builder<$this>  $query
     */
    public function scopeUnmapped(Builder $query): void
    {
        $query->whereNull('carrier_service_id');
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
     * This is the scope a mapping covers, so it is defined once here rather
     * than in each of the two places that need it: {@see ObservedServiceMapper}
     * applies a mapping across it, and {@see ObservedServiceRecorder}
     * carries that mapping onto rows created later. The two drifting apart
     * would silently unmap services.
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
