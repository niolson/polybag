<?php

namespace App\Models;

use App\Services\CacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrier extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Clear carrier services cache when carrier changes (affects active services)
        static::saved(fn () => CacheService::clearCarrierServicesCache());
        static::deleted(fn () => CacheService::clearCarrierServicesCache());
    }

    protected $fillable = [
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function carrierServices(): HasMany
    {
        return $this->hasMany(CarrierService::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
