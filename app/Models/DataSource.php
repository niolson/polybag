<?php

namespace App\Models;

use App\Contracts\SyncsProductCatalog;
use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\Enums\AmazonMarketplace;
use App\Enums\OffAmazonShippingStatus;
use App\Enums\ScheduleInterval;
use App\Enums\ShipmentStatus;
use App\Http\Integrations\Amazon\AmazonSpApiEnvironment;
use App\Http\Integrations\Amazon\Requests\SearchListingsItems;
use App\Models\Concerns\HasDefaultClient;
use App\Services\DemoLifetime;
use App\Services\ProductCatalog\ProductCatalogSyncRuns;
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
        'sells_postage',
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
        'sells_postage' => 'boolean',
        'off_amazon_shipping_status' => OffAmazonShippingStatus::class,
        'off_amazon_shipping_checked_at' => 'datetime',
        'import_updated_through' => 'datetime',
        'import_swept_at' => 'datetime',
        'import_reads' => 'array',
        'import_account_key' => 'string',
        'locations_remapped_at' => 'datetime',
        'product_catalog_sync' => 'array',
        'global_export' => 'boolean',
        'schedule_interval' => ScheduleInterval::class,
        'settings' => 'array',
        'secret_settings' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        // The primary is always one of the chosen marketplaces: a form that
        // hides it, or a marketplace removed, never leaves a stale one.
        static::saving(function (DataSource $source): void {
            $settings = $source->settings ?? [];

            if (! $source->isAmazon() || ! array_key_exists('marketplace_ids', $settings)) {
                return;
            }

            $settings['marketplace_ids'] = self::amazonMarketplaceIdsIn($settings);
            $settings['primary_marketplace_id'] = self::primaryAmazonMarketplaceIdIn($settings);
            $source->settings = $settings;

            if ($source->exists) {
                $source->sweepAddedAmazonMarketplaces();
            }
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
     * is for the import paths alone. None, once a demo has ended
     * ({@see DemoLifetime}).
     *
     * @param  Builder<DataSource>  $query
     * @return Builder<DataSource>
     */
    public function scopeImporting(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where('import_enabled', true)
            ->when(app(DemoLifetime::class)->hasEnded(), fn (Builder $q) => $q->whereRaw('1 = 0'));
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

    /**
     * Whether this connection's product catalog can be synced
     * (`product-catalog-sync/02`): an active connection whose driver reads a
     * catalog and that has what reading it needs. Import need not be on.
     */
    public function syncsProductCatalog(): bool
    {
        return $this->active
            && self::driverSyncsProductCatalog($this->source_type)
            && $this->productCatalogSetupMissing() === null;
    }

    /**
     * What this connection lacks to read its catalog, whether or not it is
     * active, or null. An Amazon listing search is by seller ID, which only
     * an OAuth connection records, and reads production listings
     * (`product-catalog-sync/04`).
     */
    public function productCatalogSetupMissing(): ?string
    {
        if (! $this->isAmazon()) {
            return null;
        }

        $settings = $this->settings ?? [];

        return match (true) {
            ($settings['auth_mode'] ?? null) !== 'authorization_code' || blank($settings['amazon_selling_partner_id'] ?? null) => 'Connect this Amazon account through OAuth (Connect Amazon) to sync its product catalog: listing search needs the seller ID that OAuth records, and a connection with its own credentials has none.',
            $this->primaryAmazonMarketplaceId() === null => 'Choose an Amazon marketplace to sync this connection\'s product catalog.',
            // The sandbox holds no seller's listings, and answers a listing search
            // with an error that says nothing about why.
            app(AmazonSpApiEnvironment::class)->usesSandbox(SearchListingsItems::class) => 'The Amazon product catalog sync reads the seller\'s live listings, so it needs Sandbox Mode (Dev Settings) off.',
            default => null,
        };
    }

    /**
     * Whether connections of this driver can list a product catalog.
     */
    public static function driverSyncsProductCatalog(?string $sourceType): bool
    {
        return $sourceType !== null && is_subclass_of($sourceType, SyncsProductCatalog::class);
    }

    /** The last product catalog sync, or null when none has run. */
    public function productCatalogSyncRun(): ?ProductCatalogSyncRun
    {
        $stored = $this->product_catalog_sync;

        return is_array($stored) ? ProductCatalogSyncRun::fromArray($stored) : null;
    }

    /**
     * Store the last product catalog sync. Only this column is written, so a
     * sync never saves over settings someone changed meanwhile, and it is
     * written whatever this instance last read. {@see ProductCatalogSyncRuns}
     * decides whether a job may write it.
     */
    public function recordProductCatalogSyncRun(ProductCatalogSyncRun $run): void
    {
        static::query()->whereKey($this->id)->update(['product_catalog_sync' => json_encode($run->toArray())]);

        $this->forceFill(['product_catalog_sync' => $run->toArray()])->syncOriginalAttribute('product_catalog_sync');
    }

    public function importsOrders(): bool
    {
        return $this->active && $this->import_enabled && ! app(DemoLifetime::class)->hasEnded();
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
     * This connection's consent to sell channel postage: Shopify's blind
     * purchase, and Amazon Buy Shipping for its own Amazon orders (ADR-0006
     * decision 6). A connection that does not sell is not asked. What
     * automation may buy from one that does is the shipping method's
     * allowance.
     *
     * Read here rather than off the column, so a driver that sells no postage
     * always answers no.
     */
    public function sellsPostage(): bool
    {
        return self::driverSellsPostage($this->source_type) && $this->sells_postage;
    }

    /**
     * Whether connections of this driver can sell channel postage at all.
     */
    public static function driverSellsPostage(?string $sourceType): bool
    {
        return in_array($sourceType, [ShopifySource::class, AmazonSource::class], true);
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
     * The seller account an Amazon import reads, for telling whether what it
     * has read so far (`import_updated_through`, `import_swept_at`,
     * `import_reads`) came from it. A connection reconnected to another seller
     * account starts over. Its marketplaces play no part: ticking another one
     * does not restart the import, because the daily sweep reads every open
     * order in the lookback whatever the cursor says (`connection-identity/01`).
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

        return hash('sha256', $account);
    }

    /**
     * Make the next import read every open order again when a marketplace was
     * added, rather than waiting up to a day for the next sweep
     * ({@see AmazonSource::SWEEP_HOURS}): the added marketplace's open orders
     * changed before the cursor, so no search for changes returns them. A
     * sweep in progress is dropped too, since it carries on with the
     * marketplaces it started with.
     *
     * A search for changes in progress is kept. It reads orders that changed,
     * and the added marketplace has none imported here to close; its open
     * orders are the sweep's, and the next search for changes, started from
     * where this one began, includes the added marketplace.
     */
    private function sweepAddedAmazonMarketplaces(): void
    {
        $before = self::amazonMarketplaceIdsIn((array) ($this->getOriginal('settings') ?? []));

        if (array_diff($this->amazonMarketplaceIds(), $before) === []) {
            return;
        }

        $reads = $this->import_reads ?? [];
        unset($reads['sweep']);

        $this->import_swept_at = null;
        $this->import_reads = array_filter($reads) === [] ? null : $reads;
    }

    /**
     * The Amazon marketplaces this connection imports from.
     *
     * @return list<string>
     */
    public function amazonMarketplaceIds(): array
    {
        return self::amazonMarketplaceIdsIn($this->settings ?? []);
    }

    /**
     * The marketplace used where there is no order to take one from: the
     * product catalog sync, and Amazon Shipping sold to other channels'
     * orders. Null when no marketplace is chosen.
     */
    public function primaryAmazonMarketplaceId(): ?string
    {
        return self::primaryAmazonMarketplaceIdIn($this->settings ?? []);
    }

    /**
     * {@see amazonMarketplaceIds()} from a source's settings.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public static function amazonMarketplaceIdsIn(array $settings): array
    {
        $marketplaceIds = $settings['marketplace_ids'] ?? null;

        if (! is_array($marketplaceIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $marketplaceIds,
            fn (mixed $marketplaceId): bool => is_string($marketplaceId) && $marketplaceId !== '',
        )));
    }

    /**
     * {@see primaryAmazonMarketplaceId()} from a source's settings: the stored
     * primary while it is one of the chosen marketplaces, else
     * {@see defaultPrimaryAmazonMarketplaceId()}.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function primaryAmazonMarketplaceIdIn(array $settings): ?string
    {
        $marketplaceIds = self::amazonMarketplaceIdsIn($settings);
        $primary = $settings['primary_marketplace_id'] ?? null;

        return is_string($primary) && in_array($primary, $marketplaceIds, true)
            ? $primary
            : self::defaultPrimaryAmazonMarketplaceId($marketplaceIds);
    }

    /**
     * The primary of these marketplaces when none is chosen: the US when it is
     * one of them, otherwise the first.
     *
     * @param  list<string>  $marketplaceIds
     */
    public static function defaultPrimaryAmazonMarketplaceId(array $marketplaceIds): ?string
    {
        return in_array(AmazonMarketplace::UnitedStates->value, $marketplaceIds, true)
            ? AmazonMarketplace::UnitedStates->value
            : ($marketplaceIds[0] ?? null);
    }

    /**
     * How many of this connection's open Shipments came from these Amazon
     * marketplaces. A marketplace removed from the connection is no longer
     * searched, so Amazon closing one of its orders would go unseen here.
     *
     * @param  list<string>  $marketplaceIds
     */
    public function openShipmentsInAmazonMarketplacesCount(array $marketplaceIds): int
    {
        if ($marketplaceIds === [] || ! $this->exists) {
            return 0;
        }

        return $this->shipments()
            ->where('status', ShipmentStatus::Open)
            ->whereIn('metadata->amazon_sales_channel->marketplaceId', $marketplaceIds)
            ->count();
    }

    /**
     * What this connection reads orders from, for telling whether two
     * connections read the same orders: a Shopify store's domain, in lower
     * case, or the Amazon seller ID OAuth records. Null when there is nothing
     * to compare: an Amazon connection with its own refresh token never
     * learns its seller ID.
     */
    public function accountIdentity(): ?string
    {
        $settings = $this->settings ?? [];

        $identity = match (true) {
            $this->isShopify() => $settings['shop_domain'] ?? null,
            $this->isAmazon() => $settings['amazon_selling_partner_id'] ?? null,
            default => null,
        };

        if (! is_string($identity) || trim($identity) === '') {
            return null;
        }

        return $this->isShopify() ? mb_strtolower(trim($identity)) : trim($identity);
    }

    /**
     * Another active connection that reads the same account as this one, or
     * null (`connection-identity/01`). Two would each import their own copy of
     * every order. An inactive connection may share an account, so an old one
     * can be kept for history; whether this one is active is the caller's to
     * weigh. A unique index cannot say this: the identity is a JSON setting,
     * and only active rows count.
     */
    public function conflictingConnection(): ?self
    {
        $identity = $this->accountIdentity();

        if ($identity === null) {
            return null;
        }

        return static::query()
            ->where('source_type', $this->source_type)
            ->where('active', true)
            ->when($this->getKey() !== null, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->orderBy('id')
            ->get()
            ->first(fn (DataSource $other): bool => $other->accountIdentity() === $identity);
    }

    /**
     * What an operator is told when {@see conflictingConnection()} names one.
     */
    public function accountConflictMessage(DataSource $other): string
    {
        return $this->isAmazon()
            ? "Another active connection, {$other->name}, already reads this seller account. Add marketplaces to it instead."
            : "Another active connection, {$other->name}, already reads this store.";
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
