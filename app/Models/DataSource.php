<?php

namespace App\Models;

use App\Enums\OffAmazonShippingStatus;
use App\Enums\PostageSetting;
use App\Enums\ScheduleInterval;
use App\Models\Concerns\HasDefaultClient;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
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

    /** The `settings` key naming the carrier Shopify's own choice is dated by. */
    public const SHIP_DATE_CARRIER_SETTING = 'ship_date_carrier_id';

    protected $table = 'data_sources';

    protected $fillable = [
        'client_id',
        'name',
        'source_type',
        'active',
        'import_enabled',
        'offers_off_amazon_shipping',
        'postage_setting',
        'off_amazon_shipping_status',
        'off_amazon_shipping_checked_at',
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
        'postage_setting' => PostageSetting::class,
        'off_amazon_shipping_status' => OffAmazonShippingStatus::class,
        'off_amazon_shipping_checked_at' => 'datetime',
        'import_updated_through' => 'datetime',
        'import_swept_at' => 'datetime',
        'import_reads' => 'array',
        'import_account_key' => 'string',
        'locations_remapped_at' => 'datetime',
        'global_export' => 'boolean',
        'schedule_interval' => ScheduleInterval::class,
        'settings' => 'array',
        'secret_settings' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::creating(function (DataSource $source): void {
            $source->postage_setting ??= PostageSetting::defaultFor($source->source_type);
        });

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

    /**
     * Whether any Amazon connection is active — what Amazon-only settings,
     * such as OTDR protection, wait on before they
     * are offered. Nothing Amazon sells can be bought without one.
     */
    public static function hasActiveAmazonConnection(): bool
    {
        return static::query()
            ->where('source_type', AmazonSource::class)
            ->where('active', true)
            ->exists();
    }

    public function importsOrders(): bool
    {
        return $this->active && $this->import_enabled;
    }

    /**
     * The carrier a purchase Shopify chooses the carrier for is dated by — the
     * connection's *Date Shopify's choice as* (ADR-0006, guideline 12).
     *
     * Only Shopify `auto` has no carrier before it is bought, so its cutoff
     * and pickup days are borrowed from the carrier it most likely goes out
     * on. The setting is a carrier id in `settings` with no foreign key, so a
     * carrier since deleted or deactivated falls back to USPS, whose policy
     * the old `Shopify` row copied. Null only when there is no USPS row
     * either, which leaves the date on the no-carrier fallback.
     */
    public function shipDateCarrier(): ?Carrier
    {
        $carrierId = ($this->settings ?? [])[self::SHIP_DATE_CARRIER_SETTING] ?? null;

        $carrier = filled($carrierId)
            ? Carrier::query()->active()->find((int) $carrierId)
            : null;

        return $carrier ?? Carrier::query()->where('name', Carrier::USPS)->first();
    }

    /**
     * This connection's consent to sell channel postage — ADR-0006 decision 6.
     *
     * Read here rather than off the column, so a driver that sells no postage
     * and a row that somehow has no value both answer *does not sell*.
     */
    public function postageSetting(): PostageSetting
    {
        return $this->postage_setting ?? PostageSetting::DoesNotSell;
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

    public function isShopify(): bool
    {
        return $this->source_type === ShopifySource::class;
    }

    /**
     * Whether this Shopify connection holds something Shopify will accept:
     * an OAuth token, or its own app's client ID and secret.
     */
    public function hasShopifyCredentials(): bool
    {
        return filled($this->secret('oauth_access_token'))
            || (filled($this->secret('client_id')) && filled($this->secret('client_secret')));
    }

    /**
     * The seller account and marketplace an Amazon import reads, for telling
     * whether what it has read so far (`import_updated_through`,
     * `import_swept_at`, `import_reads`) came from them. A connection
     * reconnected to another seller account, or pointed at another
     * marketplace, starts over.
     *
     * The account is the selling partner ID Amazon reports on an OAuth
     * connection, whose refresh token can rotate. A connection with its own
     * credentials never learns that ID, so its refresh token stands in, keyed
     * with the app key rather than stored.
     */
    public function importAccountKey(): string
    {
        return self::importAccountKeyFor([
            ...$this->settings ?? [],
            'refresh_token' => $this->secret('refresh_token'),
        ]);
    }

    /**
     * {@see importAccountKey()} from a source's merged settings and secrets.
     *
     * @param  array<string, mixed>  $config
     */
    public static function importAccountKeyFor(array $config): string
    {
        $account = ($config['auth_mode'] ?? null) === 'authorization_code'
            ? 'partner:'.($config['amazon_selling_partner_id'] ?? '')
            : 'token:'.hash_hmac('sha256', (string) ($config['refresh_token'] ?? ''), (string) config('app.key'));

        return hash('sha256', $account.'|'.($config['marketplace_id'] ?? ''));
    }

    /**
     * Whether a re-import removes the lines the source no longer sends, while
     * the Shipment has no Package. Shopify and Amazon always do: a Shopify
     * fulfillment order and an Amazon order (its ordered less fulfilled, a
     * line that reaches zero dropped — `order-reconciliation/07`) each send
     * every line still to ship.
     */
    public function hasAuthoritativeShipmentItems(): bool
    {
        return $this->isAmazon() || $this->isShopify();
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
