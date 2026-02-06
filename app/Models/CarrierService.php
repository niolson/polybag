<?php

namespace App\Models;

use App\Services\CacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CarrierService extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => CacheService::clearCarrierServicesCache());
        static::deleted(fn () => CacheService::clearCarrierServicesCache());
    }

    protected $fillable = [
        'carrier_id',
        'service_code',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function boxSizes(): BelongsToMany
    {
        return $this->belongsToMany(BoxSize::class);
    }

    public function shippingMethods(): BelongsToMany
    {
        return $this->belongsToMany(ShippingMethod::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeWithActiveCarrier(Builder $query): Builder
    {
        return $query->whereHas('carrier', fn (Builder $q) => $q->active());
    }
}
