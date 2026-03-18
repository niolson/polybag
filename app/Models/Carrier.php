<?php

namespace App\Models;

use App\Services\CacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrier extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Clear carrier services cache when carrier changes (affects active services)
        static::saved(fn () => app(CacheService::class)->clearCarrierServicesCache());
        static::deleted(fn () => app(CacheService::class)->clearCarrierServicesCache());
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

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'carrier_location')
            ->withPivot('pickup_days', 'last_end_of_day_at')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
