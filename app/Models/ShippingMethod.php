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
        'saturday_delivery',
        'active',
    ];

    protected $casts = [
        'saturday_delivery' => 'boolean',
        'active' => 'boolean',
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
}
