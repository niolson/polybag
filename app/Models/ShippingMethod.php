<?php

namespace App\Models;

use App\Enums\OtdrProtectedOrders;
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
