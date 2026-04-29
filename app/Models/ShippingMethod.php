<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_expedited' => 'boolean',
    ];

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ShippingMethodAlias::class);
    }

    public function shippingRules(): HasMany
    {
        return $this->hasMany(ShippingRule::class);
    }

    public function carrierServices(): BelongsToMany
    {
        return $this->belongsToMany(CarrierService::class);
    }

    public function specialServices(): BelongsToMany
    {
        return $this->belongsToMany(SpecialService::class)
            ->withPivot(['mode', 'config'])
            ->withTimestamps();
    }

    /**
     * Check if this shipping method has a special service set as default or required.
     */
    public function hasDefaultService(string $code): bool
    {
        return $this->specialServices()
            ->where('code', $code)
            ->whereIn('shipping_method_special_service.mode', ['default', 'required'])
            ->exists();
    }
}
