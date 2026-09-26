<?php

namespace App\Models;

use App\Services\ShipmentImport\Sources\AmazonSource;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Routes a postage source to a (location, client) slot.
 *
 * A row targets exactly one of two things: a direct `CarrierAccount`, or an
 * Amazon connection (`DataSource`) that sells Amazon Shipping for orders from
 * other channels (ADR-0002, 2026-09-22 amendment). Both kinds share the table so
 * they share one unique index and one precedence walk. A connection row sits
 * on the Amazon Shipping carrier, whose account the connection is
 * (`carrier-catalog-reset/15`).
 */
class CarrierAccountScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_account_id',
        'data_source_id',
        'location_id',
        'client_id',
        'rate_shop',
    ];

    protected function casts(): array
    {
        return [
            'rate_shop' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CarrierAccountScope $scope): void {
            if (($scope->carrier_account_id === null) === ($scope->data_source_id === null)) {
                throw new DomainException('A carrier account scope must target exactly one carrier account or one connection.');
            }

            // Always derive carrier_id from the target — never trust a caller-supplied value.
            if ($scope->carrier_account_id) {
                $account = CarrierAccount::find($scope->carrier_account_id);

                if ($account && $account->carrier_id === self::amazonCarrierId()) {
                    throw new DomainException('A carrier account cannot be scoped to Amazon Shipping. Its account is an Amazon connection.');
                }

                $scope->carrier_id = $account?->carrier_id;

                return;
            }

            $scope->guardConnectionTarget();
            $scope->carrier_id = self::amazonCarrierId(create: true);
            $scope->rate_shop = false;
        });
    }

    /**
     * The Amazon Shipping carrier row, which every connection scope sits on.
     *
     * Amazon Shipping sold to an order from another channel is a direct sale,
     * and the scoped connection is its account, so the scope sits on the
     * carrier it sells, as a `CarrierAccount`'s does (`carrier-catalog-reset/15`).
     */
    public static function amazonCarrierId(bool $create = false): ?int
    {
        $attributes = ['name' => Carrier::AMAZON_SHIPPING];

        return $create
            ? Carrier::firstOrCreate($attributes)->id
            : Carrier::where($attributes)->value('id');
    }

    /**
     * A connection with a Client may only be scoped to that Client, because
     * any wider scope would bill one client's Amazon account for another's
     * parcels. A connection with no Client can take any scope.
     */
    private function guardConnectionTarget(): void
    {
        $source = DataSource::find($this->data_source_id);

        if (! $source || $source->source_type !== AmazonSource::class) {
            throw new DomainException('Only an Amazon connection can be scoped to sell Amazon Shipping.');
        }

        if ($source->client_id !== null && ($this->client_id === null || (int) $this->client_id !== (int) $source->client_id)) {
            throw new DomainException('A connection assigned to a client can only be scoped to that client.');
        }
    }

    /**
     * Rows in any of the four precedence bands for this (location, client).
     *
     * @param  Builder<CarrierAccountScope>  $query
     */
    public function scopeMatchingSlot(Builder $query, ?int $locationId, ?int $clientId): void
    {
        $query->where(function (Builder $q) use ($locationId, $clientId): void {
            $q->where(fn ($q) => $q->where('location_id', $locationId)->where('client_id', $clientId))
                ->orWhere(fn ($q) => $q->where('location_id', $locationId)->whereNull('client_id'))
                ->orWhere(fn ($q) => $q->whereNull('location_id')->where('client_id', $clientId))
                ->orWhere(fn ($q) => $q->whereNull('location_id')->whereNull('client_id'));
        });
    }

    /**
     * Which precedence band this row is in for a (location, client), most
     * specific first:
     *
     *   0. (location, client)  — explicit client override at this location
     *   1. (location, null)    — location default
     *   2. (null, client)      — client default across all locations
     *   3. (null, null)        — global default
     */
    public function precedenceFor(?int $locationId, ?int $clientId): int
    {
        return match (true) {
            $this->location_id === $locationId && $this->client_id === $clientId => 0,
            $this->location_id === $locationId && $this->client_id === null => 1,
            $this->location_id === null && $this->client_id === $clientId => 2,
            default => 3,
        };
    }

    /**
     * @return BelongsTo<CarrierAccount, $this>
     */
    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withDefault();
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withDefault();
    }
}
