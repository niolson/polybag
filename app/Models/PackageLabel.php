<?php

namespace App\Models;

use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Enums\VoidReason;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\ShopifyAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchased instance of outbound postage for a Package. See ADR-0004.
 *
 * A row is written for every purchase and marked voided rather than deleted, so
 * the tracking number, cost and print state of a dead label survive the void
 * that clears them off the Package. The `packages` columns are the projection of
 * the one unvoided row; this row is authoritative when the two disagree.
 */
class PackageLabel extends Model
{
    use HasFactory;

    /**
     * The scalar columns that exist on both `packages` and `package_labels` and
     * must agree while the label is active, keyed by the package column and
     * naming the label column. Every writer of both rows builds both writes from
     * one array keyed by this list; nothing hand-lists the columns twice.
     *
     * @var array<string, string>
     */
    public const PROJECTED_COLUMNS = [
        'tracking_number' => 'tracking_number',
        'postage_source' => 'postage_source',
        'carrier_account_id' => 'carrier_account_id',
        'postage_data_source_id' => 'postage_data_source_id',
        'carrier' => 'carrier',
        'normalized_carrier_id' => 'normalized_carrier_id',
        'service' => 'service',
        'requested_service' => 'requested_service',
        'service_evidence' => 'service_evidence',
        'service_inference_method' => 'service_inference_method',
        'service_ruleset_version' => 'service_ruleset_version',
        'cost' => 'cost',
        'label_format' => 'label_format',
        'label_dpi' => 'label_dpi',
        'label_orientation' => 'label_orientation',
        'ship_date' => 'ship_date',
        'shipped_at' => 'purchased_at',
        'shipped_by_user_id' => 'purchased_by_user_id',
        'label_printed_at' => 'last_printed_at',
    ];

    protected $fillable = [
        'package_id',
        'tracking_number',
        'postage_source',
        'carrier_account_id',
        'postage_data_source_id',
        'carrier',
        'normalized_carrier_id',
        'carrier_service_id',
        'service',
        'requested_service',
        'service_evidence',
        'service_inference_method',
        'service_ruleset_version',
        'cost',
        'label_format',
        'label_dpi',
        'label_orientation',
        'ship_date',
        'purchased_at',
        'purchased_by_user_id',
        'source_label_reference',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
        'last_printed_at',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'postage_source' => PostageSource::class,
        'service_evidence' => ServiceEvidence::class,
        'ship_date' => 'date',
        'purchased_at' => 'datetime',
        'voided_at' => 'datetime',
        'void_reason' => VoidReason::class,
        'last_printed_at' => 'datetime',
    ];

    /**
     * The label-keyed columns a package-keyed array projects to.
     *
     * Only the projected columns are carried across; anything else in the input
     * is ignored, and a projected column missing from the input is left out so
     * the table default applies.
     *
     * @param  array<string, mixed>  $packageColumns
     * @return array<string, mixed>
     */
    public static function projectionFrom(array $packageColumns): array
    {
        $projection = [];

        foreach (self::PROJECTED_COLUMNS as $packageColumn => $labelColumn) {
            if (array_key_exists($packageColumn, $packageColumns)) {
                $projection[$labelColumn] = $packageColumns[$packageColumn];
            }
        }

        return $projection;
    }

    /**
     * Record the label a shipped Package's columns already describe.
     *
     * For the paths that put a shipped Package in the database without going
     * through `markShipped()` — the factory, the FedEx certification runners,
     * the integrity command's repair. The postage source's own label identifier
     * is read from the package's metadata while it is still there: a repair
     * runs before any void, and a void strips the identifier for good.
     */
    public static function createFromPackage(Package $package): self
    {
        if ($package->status !== PackageStatus::Shipped) {
            throw new \InvalidArgumentException('Only a shipped package describes a label to record.');
        }

        return self::create([
            'package_id' => $package->id,
            'source_label_reference' => ShopifyAdapter::shippingLabelIdFor($package)
                ?? AmazonBuyShippingAdapter::shipmentIdFor($package),
        ] + self::projectionFrom($package->getAttributes()));
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
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
    public function postageDataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'postage_data_source_id');
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function normalizedCarrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'normalized_carrier_id');
    }

    /**
     * The catalog service this Label was bought as. Null for a blind purchase,
     * whose service Shopify chose, and for a Label bought before the column
     * existed.
     *
     * @return BelongsTo<CarrierService, $this>
     */
    public function carrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function purchasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /**
     * @param  Builder<PackageLabel>  $query
     * @return Builder<PackageLabel>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /**
     * @param  Builder<PackageLabel>  $query
     * @return Builder<PackageLabel>
     */
    public function scopeVoided(Builder $query): Builder
    {
        return $query->whereNotNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
