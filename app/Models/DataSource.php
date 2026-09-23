<?php

namespace App\Models;

use App\Enums\OffAmazonShippingStatus;
use App\Enums\ScheduleInterval;
use App\Models\Concerns\HasDefaultClient;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasDefaultClient, HasFactory;

    /** @var list<string> Keys that belong in the encrypted secret_settings column. */
    public const SECRET_SETTINGS_KEYS = ['oauth_access_token', 'client_id', 'client_secret', 'refresh_token', 'db_password'];

    protected $table = 'data_sources';

    protected $fillable = [
        'client_id',
        'name',
        'source_type',
        'active',
        'import_enabled',
        'offers_off_amazon_shipping',
        'off_amazon_shipping_status',
        'off_amazon_shipping_checked_at',
        'requires_on_time_offers',
        'requires_otdr_protected_offers',
        'global_export',
        'schedule_interval',
        'settings',
        'secret_settings',
    ];

    protected $hidden = [
        'secret_settings',
    ];

    protected $casts = [
        'active' => 'boolean',
        'import_enabled' => 'boolean',
        'offers_off_amazon_shipping' => 'boolean',
        'off_amazon_shipping_status' => OffAmazonShippingStatus::class,
        'off_amazon_shipping_checked_at' => 'datetime',
        'requires_on_time_offers' => 'boolean',
        'requires_otdr_protected_offers' => 'boolean',
        'global_export' => 'boolean',
        'schedule_interval' => ScheduleInterval::class,
        'settings' => 'array',
        'secret_settings' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::saved(function (DataSource $source): void {
            if ($source->wasChanged('client_id') && $source->client_id !== null) {
                $source->dropScopesOutsideClient();
            }
        });
    }

    /**
     * Connections whose orders are imported: active, with import turned on.
     * Postage bound to the originating connection needs only `active`, so this
     * is for the import paths alone.
     *
     * @param  Builder<DataSource>  $query
     * @return Builder<DataSource>
     */
    public function scopeImporting(Builder $query): Builder
    {
        return $query->where('active', true)->where('import_enabled', true);
    }

    public function importsOrders(): bool
    {
        return $this->active && $this->import_enabled;
    }

    public function secret(string $key): mixed
    {
        return ($this->secret_settings ?? [])[$key] ?? null;
    }

    public function mergeSecret(string $key, mixed $value): void
    {
        $this->secret_settings = array_merge($this->secret_settings ?? [], [$key => $value]);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * The scope rows that route off-Amazon Amazon Shipping to this connection.
     *
     * @return HasMany<CarrierAccountScope, $this>
     */
    public function offAmazonShippingScopes(): HasMany
    {
        return $this->hasMany(CarrierAccountScope::class);
    }

    public function isAmazon(): bool
    {
        return $this->source_type === AmazonSource::class;
    }

    /**
     * Whether this connection may sell Amazon Shipping for orders from other
     * channels: an active Amazon connection with the opt-in on. Its scope rows
     * decide which packages; an opted-out connection keeps them, ignored.
     */
    public function offersOffAmazonShipping(): bool
    {
        return $this->active && $this->isAmazon() && $this->offers_off_amazon_shipping;
    }

    /**
     * The connection that sells off-Amazon Amazon Shipping at this (location,
     * client), or null.
     *
     * The sibling of {@see CarrierAccount::resolveForShipment()} for rows that
     * target a connection, on the same four precedence bands. Ineligible
     * connections are filtered out before the walk, as inactive accounts are
     * there, so a narrower row for an ineligible connection gives way to a
     * wider one. There is no rate shopping: every Amazon connection would quote
     * the same Amazon Shipping services. The unique index on
     * `(carrier_id, location_key, client_key)` leaves at most one row per band.
     */
    public static function resolveOffAmazonShipping(?int $locationId, ?int $clientId): ?self
    {
        return CarrierAccountScope::with('dataSource')
            ->whereHas('dataSource', fn (Builder $q) => $q
                ->where('active', true)
                ->where('source_type', AmazonSource::class)
                ->where('offers_off_amazon_shipping', true))
            ->matchingSlot($locationId, $clientId)
            ->get()
            ->sortBy(fn (CarrierAccountScope $scope): int => $scope->precedenceFor($locationId, $clientId))
            ->first()
            ?->dataSource;
    }

    /**
     * The slot a connection gets when it is opted in and has no scope of its
     * own: global for a connection with no Client, and its own Client, at every
     * location, for one with a Client — which is forbidden a global row.
     *
     * @return array{location_id: null, client_id: int|null}
     */
    public function defaultOffAmazonShippingSlot(): array
    {
        return ['location_id' => null, 'client_id' => $this->client_id];
    }

    /**
     * Create the default scope if the connection has none and the slot is free.
     *
     * Returns whether the connection has any scope afterwards, so a caller can
     * warn when it does not. A single-account seller never has to edit a scope.
     */
    public function ensureDefaultOffAmazonShippingScope(): bool
    {
        if ($this->offAmazonShippingScopes()->exists()) {
            return true;
        }

        $slot = $this->defaultOffAmazonShippingSlot();

        $taken = CarrierAccountScope::query()
            ->where('carrier_id', CarrierAccountScope::amazonCarrierId(create: true))
            ->whereNull('location_id')
            ->where(fn (Builder $q) => $slot['client_id'] === null
                ? $q->whereNull('client_id')
                : $q->where('client_id', $slot['client_id']))
            ->exists();

        if ($taken) {
            return false;
        }

        $this->offAmazonShippingScopes()->create($slot);

        return true;
    }

    /**
     * Replace this connection's scope rows with the given slots.
     *
     * A connection with a Client is always scoped to that Client, so its rows
     * take it whatever they say. Rows already in place are kept, not recreated.
     *
     * @param  array<int, array{location_id?: int|string|null, client_id?: int|string|null}>  $slots
     */
    public function syncOffAmazonShippingScopes(array $slots): void
    {
        $wanted = collect($slots)
            ->map(fn (array $slot): array => [
                'location_id' => filled($slot['location_id'] ?? null) ? (int) $slot['location_id'] : null,
                'client_id' => $this->client_id ?? (filled($slot['client_id'] ?? null) ? (int) $slot['client_id'] : null),
            ])
            ->unique(fn (array $slot): string => $slot['location_id'].':'.$slot['client_id'])
            ->values();

        $existing = $this->offAmazonShippingScopes()->get();

        foreach ($existing as $scope) {
            $kept = $wanted->contains(fn (array $slot): bool => $slot['location_id'] === $scope->location_id
                && $slot['client_id'] === $scope->client_id);

            if (! $kept) {
                $scope->delete();
            }
        }

        foreach ($wanted as $slot) {
            $present = $existing->contains(fn (CarrierAccountScope $scope): bool => $scope->location_id === $slot['location_id']
                && $scope->client_id === $slot['client_id']);

            if (! $present) {
                $this->offAmazonShippingScopes()->create($slot);
            }
        }
    }

    /**
     * A connection moved to a Client may no longer hold scopes for any other
     * Client, or wider ones. They are deleted rather than moved, as
     * `CarrierAccount` drops a scope whose new slot is taken, and the
     * connection is left for the operator to place deliberately.
     */
    private function dropScopesOutsideClient(): void
    {
        $stale = $this->offAmazonShippingScopes()
            ->where(fn (Builder $q) => $q->whereNull('client_id')->orWhere('client_id', '!=', $this->client_id))
            ->get();

        foreach ($stale as $scope) {
            logger()->warning('Dropped a connection scope outside the Client the connection was moved to', [
                'data_source_id' => $this->id,
                'carrier_account_scope_id' => $scope->id,
                'client_id' => $this->client_id,
            ]);

            $scope->delete();
        }
    }

    /** @return HasMany<DataSourceLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(DataSourceLocation::class);
    }
}
