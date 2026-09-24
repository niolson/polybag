<?php

namespace App\Models;

use App\DataTransferObjects\PostageSources\ServiceApprovalRules;
use App\Enums\ApprovalEffect;
use App\Enums\SourceEnvironment;
use App\Services\PostageSources\ServiceApprovalGate;
use Database\Factories\ServiceApprovalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One client's permission for automation to spend money on discovered
 * services, in one world — or an exception to it.
 *
 * ADR-0003 decision 3, and the third of the three concepts decision 2 keeps
 * apart: {@see ObservedService} is what a source said exists, its
 * `carrier_service_id` is what we decided to call it, and this is whether an
 * unattended path may buy it. The first two are statements of fact and naming;
 * only this one spends money, which is why it alone is scoped to a client and
 * to an environment.
 *
 * A row covers one service, every service of one carrier (`external_service_id`
 * is {@see WILDCARD}), or everything the source offers (both are). Its
 * {@see ApprovalEffect} says whether it approves what it covers or excepts
 * it; an exception always wins (`amazon-buy-shipping/18`).
 *
 * Absence is denial. Nothing here has to be revoked for the safe answer, and
 * an install that never approves anything behaves exactly as it did before
 * discovery existed: automation reaches only authored, seeded services.
 *
 * Grant and revoke go through {@see ServiceApprovalGate}, which is also the
 * only thing that answers the question.
 *
 * @property string $source
 * @property SourceEnvironment $environment
 * @property string $external_carrier_id
 * @property string $external_service_id
 * @property ApprovalEffect $effect
 * @property int $client_id
 * @property int|null $approved_by_user_id
 * @property string $approved_by_name
 * @property Carbon $approved_at
 */
class ServiceApproval extends Model
{
    /** @use HasFactory<ServiceApprovalFactory> */
    use HasFactory;

    /**
     * Stands for every carrier, or every service of one carrier.
     *
     * A string rather than NULL: MySQL treats NULLs as distinct in a unique
     * index, which would let the same wildcard be written twice.
     */
    public const WILDCARD = '*';

    protected $fillable = [
        'source',
        'environment',
        'external_carrier_id',
        'external_service_id',
        'effect',
        'client_id',
        'approved_by_user_id',
        'approved_by_name',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'environment' => SourceEnvironment::class,
            'effect' => ApprovalEffect::class,
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $approval): void {
            self::assertCoherentScope($approval->external_carrier_id, $approval->external_service_id);
        });
    }

    /**
     * Refuse one service of every carrier (`*` / `UPS_PTP_GND`).
     *
     * Service identifiers are only meaningful within a carrier, so the row
     * would name nothing — and read by a matcher, it would approve whatever
     * service happened to share the string.
     *
     * @throws InvalidArgumentException
     */
    public static function assertCoherentScope(string $externalCarrierId, string $externalServiceId): void
    {
        if ($externalCarrierId === self::WILDCARD && $externalServiceId !== self::WILDCARD) {
            throw new InvalidArgumentException(
                "An approval for every carrier must cover every service, not {$externalServiceId}."
            );
        }
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Every row that can bear on a purchase from this source, in this world.
     *
     * One axis narrower than {@see ObservedService::scopeSameService()}, which
     * a mapping uses, and the extra axis is `environment` — deliberately. A
     * name is a name in both worlds; a permission to spend is not. Marketplace
     * is absent from both.
     *
     * Not narrowed to a carrier or service: a wildcard row covers services it
     * does not name, so which rows match is decided in memory by
     * {@see ServiceApprovalRules}, not here.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInWorld(Builder $query, string $source, SourceEnvironment $environment): void
    {
        $query->where('source', $source)
            ->where('environment', $environment);
    }
}
