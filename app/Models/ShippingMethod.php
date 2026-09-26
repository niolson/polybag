<?php

namespace App\Models;

use App\Enums\OtdrProtectedOrders;
use App\Enums\PostageSourceKind;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'commitment_days',
        'active',
        'is_expedited',
        'excludes_late_rates',
        'otdr_protection_orders',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_expedited' => 'boolean',
        'excludes_late_rates' => 'boolean',
        'otdr_protection_orders' => AsEnumCollection::class.':'.OtdrProtectedOrders::class,
    ];

    /**
     * Every method starts with its `direct` row: direct is on by default, and
     * deleting the row turns it off (`carrier-catalog-reset/09`).
     */
    protected static function booted(): void
    {
        static::created(function (ShippingMethod $method): void {
            $method->postageSources()->firstOrCreate(['source_kind' => PostageSourceKind::Direct]);
        });
    }

    /**
     * The row that lets this kind of source sell for the method, or null when
     * it may not. Reads the loaded rows, so rating loads them once.
     */
    public function postageSourceFor(PostageSourceKind $kind): ?ShippingMethodPostageSource
    {
        return $this->postageSources->first(
            fn (ShippingMethodPostageSource $row): bool => $row->source_kind === $kind,
        );
    }

    public function allowsSource(PostageSourceKind $kind): bool
    {
        return $this->postageSourceFor($kind) !== null;
    }

    /**
     * Whether this kind may sell beyond the method's listed services —
     * Shopify's `auto`, say.
     */
    public function allowsUnlistedServices(PostageSourceKind $kind): bool
    {
        return $this->postageSourceFor($kind)?->allowsUnlistedServices() ?? false;
    }

    /**
     * Whether automation must buy an OTDR-protected offer for this Amazon
     * order (`amazon-buy-shipping/17`). An order that counts as more than
     * one kind is covered if any of them is ticked.
     */
    public function requiresOtdrProtectionFor(Shipment $shipment): bool
    {
        $required = $this->otdr_protection_orders ?? collect();

        return collect(OtdrProtectedOrders::forShipment($shipment))
            ->contains(fn (OtdrProtectedOrders $kind): bool => $required->contains($kind));
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * @return HasMany<ShippingMethodAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(ShippingMethodAlias::class);
    }

    /**
     * @return HasMany<ShippingRule, $this>
     */
    public function shippingRules(): HasMany
    {
        return $this->hasMany(ShippingRule::class);
    }

    /**
     * The source policy: one row per kind of source that may sell.
     *
     * @return HasMany<ShippingMethodPostageSource, $this>
     */
    public function postageSources(): HasMany
    {
        return $this->hasMany(ShippingMethodPostageSource::class);
    }

    /**
     * @return BelongsToMany<CarrierService, $this>
     */
    public function carrierServices(): BelongsToMany
    {
        return $this->belongsToMany(CarrierService::class);
    }

    /**
     * @return BelongsToMany<SpecialService, $this>
     */
    public function specialServices(): BelongsToMany
    {
        return $this->belongsToMany(SpecialService::class)
            ->withPivot(['mode', 'config'])
            ->withTimestamps();
    }
}
